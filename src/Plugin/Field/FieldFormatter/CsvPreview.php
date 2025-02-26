<?php

namespace Drupal\csv_field_preview\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Url;

/**
 * Field Formatter.
 *
 * @FieldFormatter(
 *  id = "csv_preview",
 *  label = @Translation("csv: Display the first page"),
 *  description = @Translation("Display the first page of the CSV file."),
 *  field_types = {"file"}
 * )
 */
class CsvPreview extends FormatterBase {

  /**
   * Get and view elements.
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = [];
    foreach ($items as $delta => $item) {
      $mimetype = $item->entity->getMimeType();
      if ($mimetype == 'text/csv') {
        $file_url = \Drupal::getContainer()->get('file_url_generator')->generateAbsoluteString($item->entity->getFileUri());
      }
      elseif ($mimetype == 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
        $file_url = Url::fromRoute('csv_field_preview.excel_download', ['file' => $item->entity->id()])->toString();
      }
      if (isset($file_url)) {
        $html = [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#attributes' => [
            'class' => ['csv-preview'],
            'id' => ['csv-preview-' . $delta],
            'file' => $file_url,
            'style' => ['height: 320px; overflow: auto; width: 100%;'],
          ],
        ];
        $elements[$delta] = $html;
      }
      else {
        $elements[$delta] = [
          '#theme' => 'file_link',
          '#file' => $item->entity,
        ];
      }
    }
    $elements['#attached']['library'][] = 'csv_field_preview/drupal.csv';
    $elements['#attached']['library'][] = 'csv_field_preview/papaparse';
    $elements['#attached']['library'][] = 'csv_field_preview/handsontable';

    return $elements;
  }

}
