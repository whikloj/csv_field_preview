<?php

namespace Drupal\csv_field_preview\Controller;


use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExcelController extends ControllerBase implements ContainerInjectionInterface
{
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
   * @param File $file The file to load.
   * @param bool $stream Whether to stream the file or not.
   * @param bool $skip Whether to skip empty lines or not.
   * @param Request $request The request object.
   * @return CacheableResponse|StreamedResponse The response object.
   */
  public function doGet(File $file, bool $stream, bool $skip, Request $request)
  {
    if ($stream) {
      return $this->streamFile($file, $skip, $request);
    }
    return $this->loadFile($file, $skip, $request);
  }

  /**
   * Get a reader for a file.
   * @param string $file The path to the file.
   * @param bool $skip_empty_lines Whether to skip empty lines or not.
   * @return IReader The reader object.
   * @throws InvalidArgumentException If the file type is invalid.
   */
  public static function getReader(string $file, bool $skip_empty_lines): IReader {
    $valid_readers = [
      IOFactory::READER_ODS,
      IOFactory::READER_XLSX,
      IOFactory::READER_XLS,
    ];
    $type = IOFactory::identify($file);
    if (!in_array($type, $valid_readers)) {
      throw new InvalidArgumentException("Invalid file type.");
    }
    $reader = IOFactory::createReader($type);
    $reader->setReadDataOnly(true);
    $reader->setIgnoreRowsWithNoCells($skip_empty_lines);
    $reader->setReadEmptyCells(false);
    return $reader;
  }

  /**
   * Stream the first sheet of an Excel file out as it's CSV equivalent.
   *
   * @param File $file The file to stream.
   * @param bool $skip_empty_lines Whether to skip empty lines or not.
   * @param Request $request The request object.
   */
  public function streamFile(File $file, bool $skip_empty_lines, Request $request) {
    $full_path = $this->getFilePath($file);
    $response = new StreamedResponse();
    $response->headers->set('Content-Type', 'text/csv');
    $response->setCallback(static function() use ($full_path, $skip_empty_lines, $mime_type): void {
      try {
        $reader = self::getReader($full_path, $skip_empty_lines);
        $spreadsheet = $reader->load($full_path);
        $i = 0;
        $data = $spreadsheet->getSheet(0)->toArray();
        foreach ($data as $row) {
          echo implode(',', $row) . PHP_EOL;
          $i += 1;
          if ($i > 1000) {
            flush();
          }
        }
      } catch (InvalidArgumentException $e) {
        echo "Error reading file: " . $e->getMessage();
      } finally {
        $data = null;
        $spreadsheet = null;
        $reader = null;
      }
    });
    return $response;
  }

  /**
   * Return the first sheet of an Excel file as a CSV string.
   *
   * @param File $file The file to load.
   * @param bool $skip_empty_lines Whether to skip empty lines or not.
   * @param Request $request The request object.
   * @return CacheableResponse The response object.
   */
  public function loadFile(File $file, bool $skip_empty_lines, Request $request) {
    $full_path = $this->getFilePath($file);
    $response = new CacheableResponse();
    $response->addCacheableDependency($file);
    $response->headers->set('Content-Type', 'text/csv');
    try {
      $reader = self::getReader($full_path, $skip_empty_lines);
      $spreadsheet = $reader->load($full_path);
      $rows = [];
      $data = $spreadsheet->getSheet(0)->toArray();
      foreach ($data as $row) {
          $rows[] = implode(',', $row) . PHP_EOL;
      }
      $response->setContent(implode('', $rows));
    } catch (InvalidArgumentException $e) {
      $response->setContent("Error reading file: " . $e->getMessage());
      $response->setStatusCode(500);
      $response->headers->set('Content-Type', 'text/plain');
      $response->setCache([
        'max-age' => 0, // Don't cache errors.
      ]);
    } finally {
      $data = null;
      $spreadsheet = null;
      $reader = null;
    }
    return $response;
  }

  /**
   * Get the file path for a file after (potentially) copying it to a local directory.
   * @param File $file The file to get the path for.
   * @return string The path to the file.
   */
  private function getFilePath(File $file): string {
    // Openspout/ XLSX Reader can't read from a stream wrapper.
    $directory = 'temporary://csv_field_preview';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $new_filename = $directory . DIRECTORY_SEPARATOR . $file->getFilename();
    if (!file_exists($new_filename)) {
      $new_filename = $this->fileSystem->copy($file->getFileUri(), $new_filename);
    }
    return $this->fileSystem->realpath($new_filename);
  }
}
