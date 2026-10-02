<?php

namespace Drupal\csv_field_preview\Controller;


use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use OpenSpout\Reader\AbstractReader;
use OpenSpout\Reader\ODS\Options as OdsOptions;
use OpenSpout\Reader\ODS\Reader as OdsReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;

class ExcelController extends ControllerBase implements ContainerInjectionInterface
{
  /**
   * Temporary directory holding the source copies and converted CSV files.
   */
  private const DIRECTORY = 'temporary://csv_field_preview';

  /**
   * @var FileSystemInterface $fileSystem
   *
   * The file system service.
   */
  private FileSystemInterface $fileSystem;

  /**
   * ExcelController constructor.
   * @param FileSystemInterface $fileSystem
   */
  public function __construct(FileSystemInterface $fileSystem) {
    $this->fileSystem = $fileSystem;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('file_system')
    );
  }

  /**
   * Handle the GET request for a file.
   *
   * The first sheet is converted to CSV once and the result is kept in the
   * temporary directory. Later requests serve that file directly, including
   * Range and conditional (ETag/Last-Modified) requests.
   *
   * @param File $file The file to load.
   * @param bool $skip Whether to skip empty lines or not.
   * @param Request $request The request object.
   * @return BinaryFileResponse The response object.
   */
  public function doGet(File $file, bool $skip, Request $request): BinaryFileResponse
  {
    $csv_path = $this->getCsvPath($file, $skip);
    $response = new BinaryFileResponse($csv_path, 200, ['Content-Type' => 'text/csv']);
    $response->setAutoEtag();
    $response->setAutoLastModified();
    return $response;
  }

  /**
   * Get the correct OpenSpout reader for the given MIME type.
   * @param string $mime_type The MIME type of the file.
   * @param bool $skip_empty_lines Whether to skip empty lines or not.
   *
   * @return \OpenSpout\Reader\AbstractReader
   */
  public static function getReader(string $mime_type, bool $skip_empty_lines): AbstractReader {
    if ($mime_type == 'application/vnd.oasis.opendocument.spreadsheet') {
      $options = new OdsOptions();
      if (!$skip_empty_lines) {
        $options->SHOULD_PRESERVE_EMPTY_ROWS = TRUE;
      }
      return new OdsReader($options);
    }
    else {
      $options = new XlsxOptions();
      if (!$skip_empty_lines) {
        $options->SHOULD_PRESERVE_EMPTY_ROWS = TRUE;
      }
      return new XlsxReader($options);
    }
  }

  /**
   * Get the path of the CSV version of a file, creating it if necessary.
   *
   * @param File $file The spreadsheet file.
   * @param bool $skip_empty_lines Whether to skip empty lines or not.
   * @return string The real path of the CSV file.
   */
  private function getCsvPath(File $file, bool $skip_empty_lines): string {
    $csv_uri = $this->getCacheUri($file, 'csv', $skip_empty_lines);
    if (!file_exists($csv_uri)) {
      $this->convertToCsv($file, $csv_uri, $skip_empty_lines);
    }
    return $this->fileSystem->realpath($csv_uri);
  }

  /**
   * Convert the first sheet of a spreadsheet to CSV.
   *
   * Writes to a temporary name and renames, so a concurrent request never
   * serves a partially written file.
   *
   * @param File $file The spreadsheet file.
   * @param string $csv_uri The URI to write the CSV to.
   * @param bool $skip_empty_lines Whether to skip empty lines or not.
   */
  private function convertToCsv(File $file, string $csv_uri, bool $skip_empty_lines): void {
    $source_path = $this->getSourceFilePath($file);
    $final = $this->fileSystem->realpath(self::DIRECTORY) . '/' . basename($csv_uri);
    $partial = $final . '.' . bin2hex(random_bytes(6)) . '.part';

    $target = fopen($partial, 'wb');
    if ($target === FALSE) {
      throw new \RuntimeException("Unable to open '$partial' for writing.");
    }
    $reader = self::getReader($file->getMimeType(), $skip_empty_lines);
    try {
      $reader->open($source_path);
      foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
          $values = array_map(static function ($cell) {
            $value = $cell->getValue();
            if ($value instanceof \DateTimeInterface) {
              return $value->format('Y-m-d H:i:s');
            }
            if (is_bool($value)) {
              return $value ? 'TRUE' : 'FALSE';
            }
            return $value;
          }, $row->getCells());
          fputcsv($target, $values, ',', '"', '');
        }
        break; // Only read the first sheet.
      }
      fclose($target);
      $target = NULL;
      if (!rename($partial, $final)) {
        throw new \RuntimeException("Unable to move '$partial' to '$final'.");
      }
    }
    catch (\Throwable $e) {
      $this->getLogger('csv_field_preview')->error('Unable to convert @uri to CSV: @message', [
        '@uri' => $file->getFileUri(),
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
    finally {
      if (is_resource($target)) {
        fclose($target);
      }
      if (file_exists($partial)) {
        @unlink($partial);
      }
      $reader->close();
    }
  }

  /**
   * Build the URI of a cached file in the temporary directory.
   *
   * The file ID and changed time mean an updated file gets a new copy.
   *
   * @param File $file The file.
   * @param string $extension The extension of the cached file.
   * @param bool|null $skip_empty_lines Include the skip setting in the name, as it changes the output.
   * @return string The URI.
   */
  private function getCacheUri(File $file, string $extension, ?bool $skip_empty_lines = NULL): string {
    $directory = self::DIRECTORY;
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new \RuntimeException("Unable to prepare directory '$directory'.");
    }
    $name = $file->id() . '-' . $file->getChangedTime();
    if ($skip_empty_lines !== NULL) {
      $name .= $skip_empty_lines ? '-skip' : '-keep';
    }
    return $directory . '/' . $name . '.' . $extension;
  }

  /**
   * Get the file path for the source file after streaming it to the temporary directory.
   *
   * @param File $file The file to get the path for.
   * @return string The path to the file.
   */
  private function getSourceFilePath(File $file): string {
    // Openspout can't read from a stream wrapper so download to a local file.
    $extension = pathinfo($file->getFilename(), PATHINFO_EXTENSION) ?: 'bin';
    $new_filename = $this->getCacheUri($file, $extension);
    if (!file_exists($new_filename)) {
      // Stream the contents because fedora:// doesn't support copy().
      // Drupal's error handler hides the warning from error_get_last().
      $error = 'unknown error';
      set_error_handler(static function (int $no, string $message) use (&$error): bool {
        $error = $message;
        return TRUE;
      });
      // The Fedora Flysystem adapter forwards the current request's Range
      // header to Fedora, but that range is meant for our response and not
      // the source file. Remove it for this read only.
      $request = \Drupal::requestStack()->getCurrentRequest();
      $range = $request?->headers->get('Range');
      $request?->headers->remove('Range');
      try {
        $source = fopen($file->getFileUri(), 'rb');
      }
      catch (\Throwable $e) {
        $source = FALSE;
        $error = get_class($e) . ': ' . $e->getMessage();
      }
      finally {
        restore_error_handler();
        if ($range !== NULL) {
          $request->headers->set('Range', $range);
        }
      }
      if ($source === FALSE) {
        throw new \RuntimeException("Unable to open '{$file->getFileUri()}' for reading: $error");
      }
      $partial = $this->fileSystem->realpath(self::DIRECTORY) . '/' . basename($new_filename) . '.' . bin2hex(random_bytes(6)) . '.part';
      $target = @fopen($partial, 'wb');
      if ($target === FALSE) {
        fclose($source);
        throw new \RuntimeException("Unable to open '$partial' for writing.");
      }
      try {
        if (stream_copy_to_stream($source, $target) === FALSE) {
          throw new \RuntimeException("Failed copying '{$file->getFileUri()}' to '$partial'.");
        }
        fclose($target);
        $target = NULL;
        if (!rename($partial, $this->fileSystem->realpath(self::DIRECTORY) . '/' . basename($new_filename))) {
          throw new \RuntimeException("Unable to move '$partial' to '$new_filename'.");
        }
      }
      finally {
        fclose($source);
        if (is_resource($target)) {
          fclose($target);
        }
        if (file_exists($partial)) {
          @unlink($partial);
        }
      }
    }
    return $this->fileSystem->realpath($new_filename);
  }
}
