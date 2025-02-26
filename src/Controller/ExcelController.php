<?php

namespace Drupal\csv_field_preview\Controller;


use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use OpenSpout\Common\Exception\IOException;
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
   * Stream the first sheete of an Excel file out as it's CSV equivalent.
   *
   * @param File $file The file to stream.
   * @param Request $request The request object.
   */
  public function streamFile(File $file, Request $request) {
    // Openspout XLSX Reader can't read from a stream wrapper.
    $directory = 'temporary://csv_field_preview';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $new_location = $this->fileSystem->copy($file->getFileUri(), $directory . '/file.csv');
    $full_path = $this->fileSystem->realpath($new_location);
    $response = new StreamedResponse();
    $response->headers->set('Content-Type', 'text/csv');
    $response->setCallback(static function() use ($full_path): void {
      try {
        $reader = new Reader();
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
}
