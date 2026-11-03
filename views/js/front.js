/**
 * SNT DJI Compensation - Front JS
 *
 * @author    SNT2
 * @copyright 2007-2026 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

document.addEventListener('DOMContentLoaded', function() {
    // Get max file size from page (set by controller)
    var maxFileSizeBytes = window.maxFileSizeBytes || (10 * 1024 * 1024);
    var productTypes = window.productTypes || { mic: 'DJI Mic', power: 'DJI Power' };

    // ===== DROPZONE FUNCTIONALITY =====

    function initDropzone(dropzoneId, inputId, previewId, options) {
        var dropzone = document.getElementById(dropzoneId);
        var input = document.getElementById(inputId);
        var preview = document.getElementById(previewId);

        if (!dropzone || !input || !preview) return;

        var opts = Object.assign({
            multiple: false,
            accept: '*/*',
            icon: 'insert_drive_file',
            onFilesChange: null
        }, options);

        // Store files in a DataTransfer to allow removal
        var dataTransfer = new DataTransfer();

        // Drag events
        ['dragenter', 'dragover'].forEach(function(eventName) {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(function(eventName) {
            dropzone.addEventListener(eventName, function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            });
        });

        // Handle drop
        dropzone.addEventListener('drop', function(e) {
            var files = e.dataTransfer.files;
            handleFiles(files);
        });

        // Handle file input change
        input.addEventListener('change', function() {
            handleFiles(this.files);
        });

        function handleFiles(files) {
            if (!opts.multiple) {
                dataTransfer = new DataTransfer();
            }

            for (var i = 0; i < files.length; i++) {
                var file = files[i];

                // Validate file type
                if (opts.accept !== '*/*') {
                    var acceptTypes = opts.accept.split(',').map(function(t) { return t.trim(); });
                    var fileExt = '.' + file.name.split('.').pop().toLowerCase();
                    var fileMime = file.type;

                    var valid = acceptTypes.some(function(accept) {
                        if (accept.startsWith('.')) {
                            return fileExt === accept.toLowerCase();
                        }
                        if (accept.endsWith('/*')) {
                            return fileMime.startsWith(accept.replace('/*', '/'));
                        }
                        return fileMime === accept;
                    });

                    if (!valid) continue;
                }

                dataTransfer.items.add(file);
            }

            input.files = dataTransfer.files;
            updatePreview();

            if (opts.onFilesChange) {
                opts.onFilesChange(dataTransfer.files);
            }
        }

        function updatePreview() {
            preview.innerHTML = '';

            if (dataTransfer.files.length === 0) {
                preview.classList.remove('active');
                dropzone.classList.remove('has-files');
                return;
            }

            preview.classList.add('active');
            dropzone.classList.add('has-files');

            for (var i = 0; i < dataTransfer.files.length; i++) {
                var file = dataTransfer.files[i];
                var item = createPreviewItem(file, i);
                preview.appendChild(item);
            }
        }

        function createPreviewItem(file, index) {
            var item = document.createElement('div');
            item.className = 'dropzone-preview-item';

            var iconName = getFileIcon(file.name);
            var fileSize = formatFileSize(file.size);

            item.innerHTML =
                '<div class="dropzone-preview-info">' +
                    '<i class="material-icons">' + iconName + '</i>' +
                    '<span class="file-name">' + escapeHtml(file.name) + '</span>' +
                    '<span class="file-size">' + fileSize + '</span>' +
                '</div>' +
                '<button type="button" class="dropzone-preview-remove" data-index="' + index + '">' +
                    '<i class="material-icons">close</i>' +
                '</button>';

            item.querySelector('.dropzone-preview-remove').addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                removeFile(index);
            });

            return item;
        }

        function removeFile(index) {
            var newTransfer = new DataTransfer();

            for (var i = 0; i < dataTransfer.files.length; i++) {
                if (i !== index) {
                    newTransfer.items.add(dataTransfer.files[i]);
                }
            }

            dataTransfer = newTransfer;
            input.files = dataTransfer.files;
            updatePreview();

            if (opts.onFilesChange) {
                opts.onFilesChange(dataTransfer.files);
            }
        }

        function getFileIcon(filename) {
            var ext = filename.split('.').pop().toLowerCase();

            if (ext === 'xlsx' || ext === 'xls') return 'description';
            if (ext === 'pdf') return 'picture_as_pdf';
            if (['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(ext) !== -1) return 'image';
            return 'insert_drive_file';
        }
    }

    function formatFileSize(bytes) {
        if (bytes < 1024) return bytes + ' o';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' Ko';
        return (bytes / (1024 * 1024)).toFixed(1) + ' Mo';
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    // Initialize dropzones
    initDropzone('xlsx-dropzone', 'xlsx_file', 'xlsx-preview', {
        multiple: false,
        accept: '.xlsx',
        icon: 'description'
    });

    initDropzone('invoices-dropzone', 'invoices', 'invoices-preview', {
        multiple: true,
        accept: '.pdf',
        icon: 'picture_as_pdf'
    });

    initDropzone('stock-proofs-dropzone', 'stock_proofs', 'stock-proofs-preview', {
        multiple: true,
        accept: 'image/*,.jpg,.jpeg,.png,.gif,.webp',
        icon: 'image',
        onFilesChange: function(files) {
            updateStockProofTypes(files);
        }
    });

    // Handle stock proof types
    function updateStockProofTypes(files) {
        var stockProofsTypesContainer = document.getElementById('stock-proofs-types');
        if (!stockProofsTypesContainer) return;

        stockProofsTypesContainer.innerHTML = '';

        if (files.length > 0) {
            var html = '<p class="mt-3"><strong>Selectionnez le type de produit pour chaque photo :</strong></p>';

            for (var i = 0; i < files.length; i++) {
                html += '<div class="stock-proof-type-select">';
                html += '<label>' + escapeHtml(files[i].name) + '</label>';
                html += '<select name="stock_proof_types[]" class="form-control form-control-sm mt-1">';

                for (var type in productTypes) {
                    html += '<option value="' + type + '">' + productTypes[type] + '</option>';
                }

                html += '</select>';
                html += '</div>';
            }

            stockProofsTypesContainer.innerHTML = html;
        }
    }

    // ===== AVERTISSEMENT TEMPLATE OBSOLETE =====
    // La modale n'est rendue par le serveur que si la derniere version du
    // template telechargee par le client differe de celle actuellement en
    // ligne. Elle remplace alors la confirmation native au moment de soumettre.
    // Le client garde toujours la possibilite d'envoyer son fichier : il assume,
    // et une declaration hors delai est de toute facon detectee en aval.

    var outdatedModal = document.getElementById('dji-template-outdated-modal');
    var submitButton = document.getElementById('dji-submit-declaration');
    var templateRefreshed = false;
    var bypassOutdatedWarning = false;
    var focusBeforeModal = null;

    function closeOutdatedModal() {
        if (!outdatedModal || outdatedModal.hidden) return;
        outdatedModal.hidden = true;
        document.body.classList.remove('dji-modal-open');
        if (focusBeforeModal && focusBeforeModal.focus) focusBeforeModal.focus();
    }

    function openOutdatedModal() {
        if (!outdatedModal) return;
        focusBeforeModal = document.activeElement;
        outdatedModal.hidden = false;
        document.body.classList.add('dji-modal-open');
        var confirmBtn = document.getElementById('dji-template-outdated-confirm');
        if (confirmBtn) confirmBtn.focus();
    }

    // Le drapeau est calcule au rendu de la page. Si le client telecharge la
    // version a jour sans recharger, l'avertissement n'a plus lieu d'etre.
    Array.prototype.forEach.call(
        document.querySelectorAll('[data-dji-template-download]'),
        function(link) {
            link.addEventListener('click', function() {
                templateRefreshed = true;
                closeOutdatedModal();
            });
        }
    );

    if (outdatedModal) {
        Array.prototype.forEach.call(
            outdatedModal.querySelectorAll('[data-dji-modal-dismiss]'),
            function(el) {
                el.addEventListener('click', function() {
                    closeOutdatedModal();
                    // Soumission interrompue : on repasse le formulaire en mode
                    // neutre pour qu'un envoi accidentel ne parte pas.
                    var actionInput = document.querySelector('input[name="action"]');
                    if (actionInput) actionInput.value = 'save';
                });
            }
        );

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' || e.key === 'Esc') closeOutdatedModal();
        });

        var outdatedConfirmBtn = document.getElementById('dji-template-outdated-confirm');
        if (outdatedConfirmBtn && submitButton) {
            outdatedConfirmBtn.addEventListener('click', function() {
                bypassOutdatedWarning = true;
                closeOutdatedModal();
                // On repasse par le vrai bouton : un form.submit() n'enverrait
                // pas submitDeclaration, que le controleur attend en POST.
                submitButton.click();
            });
        }
    }

    // ===== VALIDATION DES FICHIERS =====

    var form = document.getElementById('declaration-form');

    if (form) {
        form.addEventListener('submit', function(e) {
            var xlsxInput = document.getElementById('xlsx_file');
            var invoicesInput = document.getElementById('invoices');
            var stockProofsInputForm = document.getElementById('stock_proofs');
            var errors = [];

            // Validate XLSX
            if (xlsxInput && xlsxInput.files.length > 0) {
                var xlsxFile = xlsxInput.files[0];
                var xlsxExtension = xlsxFile.name.split('.').pop().toLowerCase();

                if (xlsxExtension !== 'xlsx') {
                    errors.push('Le fichier de declaration doit etre au format XLSX.');
                }

                if (xlsxFile.size > maxFileSizeBytes) {
                    errors.push('Le fichier XLSX depasse la taille maximale autorisee.');
                }
            }

            // Validate PDFs
            if (invoicesInput && invoicesInput.files.length > 0) {
                for (var i = 0; i < invoicesInput.files.length; i++) {
                    var file = invoicesInput.files[i];
                    var extension = file.name.split('.').pop().toLowerCase();

                    if (extension !== 'pdf') {
                        errors.push('Le fichier "' + file.name + '" n\'est pas un PDF valide.');
                    }

                    if (file.size > maxFileSizeBytes) {
                        errors.push('Le fichier "' + file.name + '" depasse la taille maximale.');
                    }
                }
            }

            // Validate images (optional stock proofs)
            if (stockProofsInputForm && stockProofsInputForm.files.length > 0) {
                var allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

                for (var j = 0; j < stockProofsInputForm.files.length; j++) {
                    var imgFile = stockProofsInputForm.files[j];
                    var imgExtension = imgFile.name.split('.').pop().toLowerCase();

                    if (allowedExtensions.indexOf(imgExtension) === -1) {
                        errors.push('Le fichier "' + imgFile.name + '" n\'est pas une image valide.');
                    }

                    if (imgFile.size > maxFileSizeBytes) {
                        errors.push('Le fichier "' + imgFile.name + '" depasse la taille maximale.');
                    }
                }
            }

            // Display errors
            if (errors.length > 0) {
                e.preventDefault();
                alert(errors.join('\n'));
                return false;
            }

            // Confirm submission
            var actionInput = document.querySelector('input[name="action"]');
            if (actionInput && actionInput.value === 'submit') {
                // Template obsolete : la modale fait office de confirmation.
                if (outdatedModal && !templateRefreshed && !bypassOutdatedWarning) {
                    e.preventDefault();
                    openOutdatedModal();
                    return false;
                }

                // Sinon, confirmation standard. On ne la pose pas deux fois
                // quand le client vient de valider la modale.
                if (!bypassOutdatedWarning
                    && !confirm('Etes-vous sur de vouloir soumettre cette declaration ? Vous ne pourrez plus la modifier apres soumission.')) {
                    e.preventDefault();
                    actionInput.value = 'save';
                    return false;
                }
            }
        });
    }

});
