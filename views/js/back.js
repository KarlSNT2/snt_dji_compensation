/**
 * SNT DJI Compensation - Back Office JS
 *
 * @author    SNT2
 * @copyright 2007-2026 PrestaShop SA
 * @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
 */

$(document).ready(function() {
    // Change declaration status
    $(document).on('click', '.change-status', function(e) {
        e.preventDefault();

        var $this = $(this);
        var $row = $this.closest('tr');
        var idDeclaration = $row.data('id');
        var status = $this.data('status');

        if (!confirm('Voulez-vous changer le statut de cette declaration ?')) {
            return;
        }

        $.ajax({
            url: currentIndex + '&ajax=1&action=updateDeclarationStatus&token=' + token,
            type: 'POST',
            data: {
                id_declaration: idDeclaration,
                status: status
            },
            dataType: 'json',
            beforeSend: function() {
                $row.addClass('loading');
            },
            success: function(response) {
                $row.removeClass('loading');

                if (response.success) {
                    var $statusLabel = $row.find('.declaration-status');
                    $statusLabel.text(response.status_label);
                    $statusLabel.attr('class', 'label label-' + response.status_class + ' declaration-status');
                    showSuccessMessage(response.message);
                } else {
                    showErrorMessage(response.message);
                }
            },
            error: function() {
                $row.removeClass('loading');
                showErrorMessage('Une erreur est survenue.');
            }
        });
    });

    // Download file
    $(document).on('click', '.download-file', function(e) {
        e.preventDefault();

        var $this = $(this);
        var type = $this.data('type');
        var id = $this.data('id');

        $.ajax({
            url: currentIndex + '&ajax=1&action=getFileUrl&token=' + token,
            type: 'POST',
            data: {
                type: type,
                id: id
            },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.url) {
                    window.open(response.url, '_blank');
                } else {
                    showErrorMessage(response.message || 'Impossible de recuperer le fichier.');
                }
            },
            error: function() {
                showErrorMessage('Une erreur est survenue.');
            }
        });
    });

    // View declaration details
    $(document).on('click', '.view-details', function(e) {
        e.preventDefault();

        var idDeclaration = $(this).data('id');

        var content = '<p class="text-center">Chargement des details...</p>';
        $('#declaration-details-content').html(content);
        $('#declaration-details-modal').modal('show');
    });

    // Update admin notes
    $(document).on('blur', '.admin-notes-textarea', function() {
        var $this = $(this);
        var idDeclaration = $this.data('id');
        var notes = $this.val();

        $.ajax({
            url: currentIndex + '&ajax=1&action=updateDeclarationNotes&token=' + token,
            type: 'POST',
            data: {
                id_declaration: idDeclaration,
                notes: notes
            },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    $this.css('border-color', '#28a745');
                    setTimeout(function() {
                        $this.css('border-color', '');
                    }, 2000);
                } else {
                    showErrorMessage(response.message);
                }
            }
        });
    });

    // Helper functions
    function showSuccessMessage(message) {
        if (typeof $.growl !== 'undefined') {
            $.growl.notice({
                title: '',
                message: message
            });
        } else {
            alert(message);
        }
    }

    function showErrorMessage(message) {
        if (typeof $.growl !== 'undefined') {
            $.growl.error({
                title: '',
                message: message
            });
        } else {
            alert(message);
        }
    }
});
