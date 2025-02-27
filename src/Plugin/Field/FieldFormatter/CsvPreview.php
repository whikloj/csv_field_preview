<?php

namespace Drupal\csv_field_preview\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Field Formatter.
 *
 * @FieldFormatter(
 *  id = "csv_preview",
 *  label = @Translation("csv_preview: Display the first page"),
 *  description = @Translation("Display the first page of the Excel/CSV file."),
 *  field_types = {"file"}
 * )
 */
class CsvPreview extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
        'stream_response' => false,
        'skip_empty_rows' => true,
      ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::settingsForm($form, $form_state);
    $form['stream_response'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Stream response'),
      '#description' => $this->t('Stream the response of any conversion from Excel to CSV, instead of downloading it.' .
        ' This is useful for large files but the response is not cached.'),
      '#default_value' => $this->getSetting('stream_response'),
    ];
    $form['skip_empty_rows'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Skip empty rows'),
      '#description' => $this->t('Skip empty rows when parsing the Excel/CSV file.'),
      '#default_value' => $this->getSetting('skip_empty_rows'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $stream = $this->getSetting('stream_response');
    $skip = $this->getSetting('skip_empty_rows');
    $message = $stream ? $this->t('Stream response') : $this->t('Download response');
    $message .= $skip ? $this->t(' and skip empty lines') : $this->t(' and do not skip empty lines');
    $summary[] = $message;
    return $summary;
  }

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
        $file_url = Url::fromRoute(
          'csv_field_preview.excel_download',
          [
            'file' => $item->entity->id(),
            'stream' => $this->getSetting('stream_response'),
            'skip' => $this->getSetting('skip_empty_rows'),
          ]
        )->toString();
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
    $elements['#attached']['drupalSettings']['csvFieldPreview']['skipEmptyRows'] = $this->getSetting('skip_empty_rows');

    return $elements;
  }

}
