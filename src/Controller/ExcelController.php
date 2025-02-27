<?php

namespace Drupal\csv_field_preview\Controller;


use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\Exception\ReaderNotOpenedException;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
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
    $response->setCallback(static function() use ($full_path, $skip_empty_lines): void {
      try {
        if (!$skip_empty_lines) {
          $options = new Options();
          $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
          $reader = new Reader($options);
        }
        else {
          $reader = new Reader();
        }
        $reader->open($full_path);
        $i = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
          foreach ($sheet->getRowIterator() as $row) {
            $cells = array_map(function($cell) {
              return $cell->getValue();
            }, $row->getCells());
            echo implode(',', $cells) . PHP_EOL;
            $i += 1;
            if ($i > 1000) {
              flush();
            }
          }
          break; // Only read the first sheet.
        }
      } catch (IOException $e) {
        echo "Error reading file: " . $e->getMessage();
      } finally {
        $reader->close();
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
      if (!$skip_empty_lines) {
        $options = new Options();
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
      }
      else {
        $reader = new Reader();
      }

      $reader->open($full_path);
      $rows = [];
      foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
          $cells = array_map(function ($cell) {
            return $cell->getValue();
          }, $row->getCells());
          $rows[] = implode(',', $cells) . PHP_EOL;
        }
        break; // Only read the first sheet.
      }
      $response->setContent(implode('', $rows));
    } catch (IOException | ReaderNotOpenedException $e) {
      $response->setContent("Error reading file: " . $e->getMessage());
      $response->setStatusCode(500);
    } finally {
      $reader->close();
    }
    return $response;
  }

  /**
   * Get the file path for a file after (potentially) copying it to a local directory.
   * @param File $file The file to get the path for.
   * @return string The path to the file.
   */
  private function getFilePath(File $file): string {
    // Openspout XLSX Reader can't read from a stream wrapper.
    $directory = 'temporary://csv_field_preview';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $new_filename = $directory . DIRECTORY_SEPARATOR . $file->getFilename() . '.csv';
    if (!file_exists($new_filename)) {
      $new_filename = $this->fileSystem->copy($file->getFileUri(), $new_filename);
    }
    return $this->fileSystem->realpath($new_filename);
  }
}
