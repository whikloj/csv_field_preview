(function ($, Drupal) {
    Drupal.behaviors.csv_field_preview = {
        attach: function (context) {
            const trimTrailingEmptyElements = arr => {
                let trimmedArray = [...arr];
                while (trimmedArray.length > 0 && trimmedArray[trimmedArray.length - 1] === "") {
                    trimmedArray.pop();
                }
                return trimmedArray;
            };
            const matchArrayLength = (arr, length) => {
                let matchedArray = [...arr];
                while (matchedArray.length < length) {
                    matchedArray.push("");
                }
                while (matchedArray.length > length) {
                    matchedArray.pop();
                }
                return matchedArray;
            }

            const handsontableContainer = context.getElementsByClassName("csv-preview");
            if (handsontableContainer.length > 0) {
                let handsontable = null;
                for (file of handsontableContainer) {
                    const url = file.getAttribute('file');
                    Papa.parse(url, {
                      download: true,
                      header: false, // false returns array of arrays, true returns array of objects
                      skipEmptyLines: true,
                      complete: function (results) {
                        console.log("Finished:", results.data);
                        let maxLength = 0;
                        const trimmedDatas = results.data.map(trimTrailingEmptyElements);
                        trimmedDatas.forEach(row => {
                          maxLength = Math.max(maxLength, row.length);
                        });
                        const newFields = trimmedDatas.map(row => {
                          return matchArrayLength(row, maxLength);
                        });
                        handsontable = new Handsontable(file, {
                          data: newFields,
                          colWidths: 100,
                          width: '100%',
                          height: 320,
                          rowHeights: 23,
                          rowHeaders: true,
                          colHeaders: results.meta.fields,
                        });
                      }
                    });

                    // reset container
                    handsontableContainer.innerHTML = '';
                }
            }
        }
    };
})(jQuery, Drupal);
