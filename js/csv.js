(function (Drupal) {
    Drupal.behaviors.csv_field_preview = {
        attach: function (context, settings) {
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

          /**
           * Parse the CSV file and update the Handsontable container
           * @param url Url of the CSV file
           */
            const processStream = (url) => {
              const dataHolder = [];
              let container = null;

              Papa.parse(url, {
                download: true,
                header: false, // false returns array of arrays, true returns array of objects
                skipEmptyLines: settings.csvFieldPreview.skipEmptyRows,
                step: function(results) {
                  // Update data on each row.
                  console.log("Row:", results.data);
                  dataHolder.push(results.data);
                  if (container === null) {
                    container = new Handsontable(file, {
                      data: dataHolder,
                      colWidths: 100,
                      width: '100%',
                      height: 320,
                      rowHeights: 23,
                      readOnly: true,
                      rowHeaders: true,
                      colHeaders: results.meta.fields
                    });
                  } else {
                    container.updateSettings({
                      data: dataHolder
                    });
                  }
                  return container;
                },
                complete: function() {
                  console.log("All data loaded");
                  // Once all the data is loaded we can reformat to strip blank columns
                  let maxLength = 0;
                  const trimmedDatas = dataHolder.map(trimTrailingEmptyElements);
                  trimmedDatas.forEach(row => {
                    maxLength = Math.max(maxLength, row.length);
                  });
                  const newFields = trimmedDatas.map(row => {
                    return matchArrayLength(row, maxLength);
                  });
                  container.updateSettings({
                    data: newFields
                  });
                }
              });
            }

            const handsontableContainer = context.getElementsByClassName("csv-preview");
            if (handsontableContainer.length > 0) {
                for (file of handsontableContainer) {
                    const url = file.getAttribute('file');
                    processStream(url);
                    // reset container
                    handsontableContainer.innerHTML = '';
                }
            }
        }
    };
})(Drupal);
