(function () {
    'use strict';

    var config = window.documentViewerConfig || {};
    var status = document.getElementById('viewer-status');
    var content = document.getElementById('viewer-content');
    var maximumFileBytes = 25 * 1024 * 1024;
    var maximumExpandedBytes = 100 * 1024 * 1024;
    var maximumRowsPerSheet = 2000;
    var maximumColumnsPerSheet = 100;
    var maximumCellsPerSheet = 20000;

    function setStatus(message, isError) {
        status.textContent = message;
        status.dataset.state = isError ? 'error' : 'ready';
    }

    function fetchDocument() {
        return fetch(config.fileUrl, {
            credentials: 'same-origin',
            headers: { Accept: config.mimeType }
        }).then(function (response) {
            if (!response.ok) {
                throw new Error(response.status === 403
                    ? 'You do not have permission to view this file.'
                    : 'The document could not be loaded (HTTP ' + response.status + ').');
            }
            return response.blob();
        }).then(function (blob) {
            if (blob.size > maximumFileBytes) {
                throw new Error('This file is larger than the 25 MB preview limit. Download the original to view it.');
            }
            return blob.arrayBuffer();
        });
    }

    function parseXml(xmlText, label) {
        var xml = new DOMParser().parseFromString(xmlText, 'application/xml');
        if (xml.getElementsByTagName('parsererror').length) {
            throw new Error('The ' + label + ' contains invalid XML.');
        }
        return xml;
    }

    function xmlElements(parent, localName) {
        return Array.prototype.slice.call(parent.getElementsByTagNameNS('*', localName));
    }

    function normalizeWorksheetPath(target) {
        var segments = target.charAt(0) === '/'
            ? target.replace(/^\/+/, '').split('/')
            : ('xl/' + target).split('/');
        var normalized = [];
        segments.forEach(function (segment) {
            if (!segment || segment === '.') {
                return;
            }
            if (segment === '..') {
                normalized.pop();
                return;
            }
            normalized.push(segment);
        });
        var path = normalized.join('/');
        if (path.indexOf('xl/') !== 0) {
            throw new Error('The workbook contains an invalid worksheet path.');
        }
        return path;
    }

    function cellColumnIndex(reference) {
        var letters = (reference.match(/^[A-Z]+/i) || [''])[0].toUpperCase();
        var column = 0;
        for (var i = 0; i < letters.length; i += 1) {
            column = column * 26 + letters.charCodeAt(i) - 64;
        }
        return column - 1;
    }

    function getCellValue(cell, sharedStrings) {
        var type = cell.getAttribute('t') || '';
        if (type === 'inlineStr') {
            var inline = xmlElements(cell, 'is')[0];
            return inline ? xmlElements(inline, 't').map(function (node) {
                return node.textContent || '';
            }).join('') : '';
        }
        var value = xmlElements(cell, 'v')[0];
        var raw = value ? value.textContent || '' : '';
        if (type === 's') {
            var sharedIndex = Number(raw);
            return Number.isInteger(sharedIndex) ? (sharedStrings[sharedIndex] || '') : '';
        }
        if (type === 'b') {
            return raw === '1' ? 'TRUE' : 'FALSE';
        }
        return raw;
    }

    function makeSheetTable(sheetXml, sheetName) {
        var rows = xmlElements(sheetXml, 'row');
        var table = document.createElement('table');
        var caption = document.createElement('caption');
        caption.textContent = sheetName;
        table.appendChild(caption);
        var body = document.createElement('tbody');
        var cellCount = 0;
        var truncated = rows.length > maximumRowsPerSheet;

        rows.slice(0, maximumRowsPerSheet).forEach(function (rowXml) {
            var row = document.createElement('tr');
            var rowHeading = document.createElement('th');
            rowHeading.scope = 'row';
            rowHeading.textContent = rowXml.getAttribute('r') || '';
            row.appendChild(rowHeading);
            var cells = xmlElements(rowXml, 'c');
            cells.forEach(function (cellXml) {
                if (cellCount >= maximumCellsPerSheet) {
                    truncated = true;
                    return;
                }
                var column = cellColumnIndex(cellXml.getAttribute('r') || '');
                if (column < 0 || column >= maximumColumnsPerSheet) {
                    truncated = true;
                    return;
                }
                while (row.children.length - 1 < column) {
                    row.appendChild(document.createElement('td'));
                }
                var cell = document.createElement('td');
                cell.textContent = getCellValue(cellXml, makeSheetTable.sharedStrings);
                row.appendChild(cell);
                cellCount += 1;
            });
            body.appendChild(row);
        });
        table.appendChild(body);
        return { table: table, truncated: truncated };
    }

    function renderWorkbook(buffer) {
        return JSZip.loadAsync(buffer).then(function (zip) {
            var expandedBytes = Object.keys(zip.files).reduce(function (total, name) {
                var item = zip.files[name];
                return total + (item._data && Number(item._data.uncompressedSize) || 0);
            }, 0);
            if (expandedBytes > maximumExpandedBytes) {
                throw new Error('The workbook expands beyond the safe preview size. Download the original to view it.');
            }

            var workbookFile = zip.file('xl/workbook.xml');
            var relationshipsFile = zip.file('xl/_rels/workbook.xml.rels');
            if (!workbookFile || !relationshipsFile) {
                throw new Error('This file is not a supported XLSX workbook.');
            }
            var sharedStringsPromise = zip.file('xl/sharedStrings.xml')
                ? zip.file('xl/sharedStrings.xml').async('string').then(function (text) {
                    return xmlElements(parseXml(text, 'shared strings'), 'si').map(function (item) {
                        return xmlElements(item, 't').map(function (part) {
                            return part.textContent || '';
                        }).join('');
                    });
                })
                : Promise.resolve([]);

            return Promise.all([
                workbookFile.async('string'),
                relationshipsFile.async('string'),
                sharedStringsPromise
            ]).then(function (parts) {
                var workbook = parseXml(parts[0], 'workbook');
                var relationships = parseXml(parts[1], 'workbook relationships');
                var relationMap = {};
                xmlElements(relationships, 'Relationship').forEach(function (relation) {
                    relationMap[relation.getAttribute('Id')] = relation.getAttribute('Target');
                });
                var sheets = xmlElements(workbook, 'sheet');
                if (!sheets.length) {
                    throw new Error('No worksheets were found in this workbook.');
                }
                makeSheetTable.sharedStrings = parts[2];
                var tabs = document.createElement('div');
                tabs.className = 'spreadsheet-tabs';
                tabs.setAttribute('role', 'tablist');
                var panel = document.createElement('div');
                panel.className = 'spreadsheet-preview';
                var workbookTruncated = false;
                var sheetPromises = sheets.map(function (sheet, index) {
                    var relationId = sheet.getAttributeNS(
                        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                        'id'
                    );
                    var target = relationMap[relationId];
                    if (!target) {
                        throw new Error('A worksheet reference could not be resolved.');
                    }
                    var path = normalizeWorksheetPath(target);
                    var file = zip.file(path);
                    if (!file) {
                        throw new Error('A worksheet could not be read from the workbook.');
                    }
                    return file.async('string').then(function (text) {
                        var result = makeSheetTable(parseXml(text, 'worksheet'), sheet.getAttribute('name') || ('Sheet ' + (index + 1)));
                        workbookTruncated = workbookTruncated || result.truncated;
                        return { name: sheet.getAttribute('name') || ('Sheet ' + (index + 1)), table: result.table };
                    });
                });
                return Promise.all(sheetPromises).then(function (renderedSheets) {
                    renderedSheets.forEach(function (sheet, index) {
                        var button = document.createElement('button');
                        button.type = 'button';
                        button.setAttribute('role', 'tab');
                        button.setAttribute('aria-selected', index === 0 ? 'true' : 'false');
                        button.textContent = sheet.name;
                        button.addEventListener('click', function () {
                            Array.prototype.forEach.call(tabs.children, function (tab) {
                                tab.setAttribute('aria-selected', tab === button ? 'true' : 'false');
                            });
                            panel.replaceChildren(sheet.table.cloneNode(true));
                        });
                        tabs.appendChild(button);
                    });
                    content.classList.add('spreadsheet-content');
                    content.replaceChildren(tabs, panel);
                    panel.appendChild(renderedSheets[0].table);
                    setStatus(
                        workbookTruncated
                            ? 'Read-only preview. Large worksheet sections are clipped for performance; download the original to see all cells.'
                            : 'Read-only Excel preview. Formulas are not recalculated; displayed values are those saved in the file.',
                        false
                    );
                });
            });
        });
    }

    function renderWord(buffer) {
        return window.mammoth.convertToHtml({ arrayBuffer: buffer }).then(function (result) {
            var preview = document.createElement('article');
            preview.className = 'word-preview';
            preview.innerHTML = DOMPurify.sanitize(result.value, { USE_PROFILES: { html: true } });
            content.replaceChildren(preview);
            var messages = result.messages && result.messages.length
                ? ' Some Word formatting or unsupported objects may not appear in this preview.'
                : '';
            setStatus('Read-only Word preview.' + messages, false);
        });
    }

    if (!config.fileUrl || !config.mimeType) {
        setStatus('The preview could not be initialized.', true);
        return;
    }

    fetchDocument().then(function (buffer) {
        if (config.mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            return renderWord(buffer);
        }
        if (config.mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
            return renderWorkbook(buffer);
        }
        throw new Error('This file type is not supported for browser preview.');
    }).catch(function (error) {
        setStatus(error && error.message ? error.message : 'The document preview failed to load.', true);
    });
}());
