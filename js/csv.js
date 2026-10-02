(function (Drupal) {
    Drupal.behaviors.csv_field_preview = {
        attach: function (context, settings) {
          // Rows can have different lengths. Pad them to the widest row so every
          // row has the same columns, the cell contents are left untouched.
          const padRows = (rows) => {
            let width = 0;
            for (const row of rows) {
              if (row.length > width) {
                width = row.length;
              }
            }
            for (const row of rows) {
              while (row.length < width) {
                row.push('');
              }
            }
            return rows;
          };

          /**
           * Parse the CSV file and update the Handsontable container
           * @param url Url of the CSV file
           * @param container The Handsontable container to update
           */
            const processStream = (url, container) => {
              let hands = null;

              Papa.parse(url, {
                download: true,
                header: false, // false returns array of arrays, true returns array of objects
                skipEmptyLines: settings.csvFieldPreview.skipEmptyRows,
                complete: function(results) {
                  const newFields = padRows(results.data);
                  hands = new Handsontable(container, {
                    data: newFields,
                    colWidths: 100,
                    width: '100%',
                    height: 320,
                    rowHeights: 23,
                    readOnly: true,
                    rowHeaders: true,
                    colHeaders: true,
                  });
                }
              });
            }

            once('csv-field-preview', 'div.csv-preview', context).forEach((element) => {
                const url = element.getAttribute('file');
                processStream(url, element);
            });
        }
    };
})(Drupal);
