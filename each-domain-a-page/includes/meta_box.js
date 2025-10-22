jQuery(document).ready(function ($) {
    let mediaUploader, favicons;
    const input = document.getElementById('ruigehond007_favicons'),
        translations = window.ruigehond007_translations || {};
    if (!input) {
        console.error('Favicon input field not found');
        return;
    }
    try {
        favicons = JSON.parse(input.value);
        if (!Array.isArray(favicons)) {
            console.error('favicons entry not JSON array:', favicons);
            favicons = [];
        }
    } catch (e) {
        console.error('Error parsing favicons JSON:', e);
        favicons = [];
    }

    $('.upload-favicon-button').click(function (e) {
        e.preventDefault();
        if (mediaUploader) {
            mediaUploader.open();
            return;
        }
        mediaUploader = wp.media({
            title: translations.select || 'Select favicons',
            button: {
                text: translations.use || 'Use image(s)'
            },
            multiple: true
        });
        mediaUploader.on('select', function () {
            const attachments = mediaUploader.state().get('selection').toJSON(),
                table = document.getElementById('ruigehond007_favicons_list');
            for (let i = 0; i < attachments.length; i++) {
                const attachment = attachments[i];
                if (!attachment.url || 'image' !== attachment.type) {
                    console.error('Skipping non-image attachment:', attachment);
                    continue;
                }
                favicons.push({
                    url: attachment.url,
                    type: attachment.mime,
                    sizes: attachment.width && attachment.height ? `${attachment.width}x${attachment.height}` : ''
                });
                // display in table
                if (!table) return;
                const row = table.insertRow();
                const cell1 = row.insertCell(0);
                const cell2 = row.insertCell(1);
                const cell3 = row.insertCell(2);
                const cell4 = row.insertCell(3);
                const img = document.createElement('img');
                img.src = attachment.url;
                cell1.appendChild(img);
                cell2.textContent = attachment.mime;
                cell3.textContent = attachment.width && attachment.height ? `${attachment.width}x${attachment.height}` : '';
                const deleteButton = document.createElement('input');
                deleteButton.type = 'button';
                deleteButton.value = translations.delete || 'Delete';
                deleteButton.className = 'delete-favicon-button button button-secondary';
                cell4.appendChild(deleteButton);
            }
            input.value = JSON.stringify(favicons);
        });
        mediaUploader.open();
    });

    // Delete favicon row
    $(document).on('click', '.delete-favicon-button', function (e) {
        e.preventDefault();
        const tr = $(this).closest('tr');
        favicons.splice(tr.index(), 1);
        input.value = JSON.stringify(favicons);
        tr.remove();
    });
});

