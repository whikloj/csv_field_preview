# csv_field_preview
A drupal module that provides a field formatter to display CSV and Excel files.

This module uses Handsontable to show csv file attachments. It is using [version 6.2.2](https://github.com/handsontable/handsontable/tree/6.2.2) which is the last version with a pure MIT license.

## Installation
This module is not currently available on Drupal.org. You can download the module from the GitHub repository and install it manually.
You will also need to install the following libraries:
* [OpenSpout ^4](https://github.com/openspout/openspout)

Once you have cloned this repository into your Drupal 10, `web/modules/contrib` directory.
Then move to the root of your Drupal 10 installation and run the following command:
```bash
composer require "openspout/openspout:^4"
```
Then, you can install the module by running the following command:
```bash
drush en csv_field_preview
```

## Usage
After installing the module, you can go to the content type that you want to add the field formatter to.
Then, you can add a new field and select the field type as "File" and the widget as "File".

## Configuration
For a File field, you can now select the Format of "csv_preview: Display the first page".

Once selected you can also configure the formatter to:
* Stream response - For Excel files, this will change the response to a stream response. It is not cacheable but faster for large files.
* Skip empty rows - By default we skip empty rows to make the table smaller. This allows you to display empty rows.

## Authors
* Mengyu Zang <mzang@upei.ca>
* [Jared Whiklo](https://github.com/whikloj) 
