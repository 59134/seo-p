import $ from './jquery';
import tinymce from '../../../public/tinymce/tinymce.min.js';
import { Calendar } from '@fullcalendar/core';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import frLocale from '@fullcalendar/core/locales/fr';

$(document).ready(function () {
    $('#iso-flters2 li').on('click', function () {
        // Retirer la classe 'filter-active' de tous les éléments
        $('#iso-flters2 li').removeClass('filter-active');

        // Ajouter la classe 'filter-active' à l'élément cliqué
        $(this).addClass('filter-active');

        // Récupérer la valeur de l'attribut 'data-filter' de l'élément cliqué
        var filterValue = $(this).data('filter');

        // Afficher les éléments correspondant au filtre et masquer les autres
        $('.dashbordStat .iso-item').hide();
        $(filterValue).show();
    });

    // Activer la classe 'filter-vitrine' et masquer la classe 'filter-catalogue' au chargement initial
    $('.dashbordStat .iso-item.filter-vitrine').show();
    $('.dashbordStat .iso-item.filter-catalogue').hide();
    $('#iso-flters2 li[data-filter=".filter-vitrine"]').addClass('filter-active');
});

//Gestion de recuperation de ip
$(".linkIP").click(function () {
    $.getJSON("https://api.ipify.org?format=jsonp&callback=?",
        function (json) {
            $('.field-collection-item:last .form-control').val(json.ip)
        }
    );
});

$('.idSlugRef').click(function () {
    // Créer un input temporaire pour sélectionner et copier le texte
    var $temp = $("<input>");
    $("body").append($temp);

    // Utiliser data-value ou le texte interne du span
    $temp.val($(this).data('value') || $(this).text()).select();

    // Copie le texte dans le presse-papiers
    try {
        var successful = navigator.clipboard.writeText($(this).data('value'));
        var msg = successful ? 'succès' : 'échec';
        console.log('Copie dans le presse-papiers ' + msg);
        alert('Texte copié: ' + ($temp.val())); // Affiche une alerte pour confirmer la copie
    } catch (err) {
        console.error('Erreur lors de la copie', err);
    }

    $temp.remove(); // Enlever l'input temporaire après la copie
});

const plainTextareaFieldNames = [
    'contentJson',
    'faqJson',
    'internalLinksJson',
    'templateCopyJson',
    'schemaJsonText',
];

plainTextareaFieldNames.forEach(function (fieldName) {
    $('textarea[name$="[' + fieldName + ']"]').addClass('seo-plain-text');
});

// TinyMCE
tinymce.init({
    selector: "textarea:not(.seo-plain-text)", resize: true,
    theme: 'silver',
    mobile: {
        theme: 'silver'
    },
    plugins: [
        "advlist", "code", "autolink", "link", "image", "lists", "charmap", "preview", "fullscreen", "anchor", "pagebreak", "searchreplace", "wordcount", "visualblocks", "visualchars", "insertdatetime", "media", "nonbreaking", "table", "directionality", "emoticons", "template"
    ],
    style_formats: [
        { title: 'Titre', block: 'h2' },
        { title: 'Sous titre', block: 'h3' },
        { title: 'Texte petit', block: 'p', styles: { 'font-size': '0.8em' } },
        { title: 'Texte normal', block: 'p', styles: { 'font-size': '1em' } },
        { title: 'Texte moyen', block: 'p', styles: { 'font-size': '1.2em' } }
    ],
    link_class_list: [
        { title: 'Normal', value: '' },
        { title: 'Bouton', value: 'btn-wt' },
        { title: 'Lien externe', value: 'link-track btn-wt' },
    ],
    advlist_bullet_styles: "default,square,upper-roman,georgian",
    advlist_number_styles: "default,lower-alpha",
    target_list: [
        { title: 'None', value: '' },
        { title: 'Nouvelle page', value: '_blank' }
    ],
    rel_list: [
        { title: '-', value: '' },
        { title: 'nofollow', value: 'nofollow' }
    ],
    link_rel_list: [
        { title: 'Aucun', value: '' },
        { title: 'nofollow', value: 'nofollow' },
        { title: 'noopener', value: 'noopener' },
        { title: 'noreferrer', value: 'noreferrer' }
    ],
    image_caption: true,

    link_list: [
        { title: 'Contact', value: '/contact' },
        { title: '#Contact', value: '#contact' }
    ],
    toolbar1: "undo redo | styleselect fontsizeselect | removeformat  bold italic underline strikethrough |  alignleft aligncenter alignright alignjustify hr forecolor ",
    toolbar2: "searchreplace  bullist numlist outdent indent| link unlink | image media table | preview charmap fullscreen code template",
    templates: [
        { title: 'Image + text', description: 'Ensemble de trois elements [image + text]', url: 'tinymce/templatesTinyMCE/blockImages.html.twig' },
        { title: 'Titre + text + bouton', description: 'Ensemble de deux elements [titre + text + bouton]', url: 'tinymce/templatesTinyMCE/doubleBlock.html.twig' },
        { title: 'Image + titre + text', description: 'Ensemble de deux elements [image + titre + text]', url: 'tinymce/templatesTinyMCE/doubleVignette.html.twig' },
        { title: 'Encadré', description: 'Block encadré [titre + text + bouton]', url: 'tinymce/templatesTinyMCE/blockEncadre.html.twig' },
        { title: 'Prix', description: 'Block 3 tarifs [titre + text + bouton]', url: 'tinymce/templatesTinyMCE/prix.html.twig' },
    ],
    fontsize_formats: '0.8em 1em 1.2em 1.4em 1.6em 1.8em 2em 2.5em 3em 4em',
    image_advtab: true,
    link_title: false,
    browser_spellcheck: true,
    relative_urls: true,
    remove_script_host: true,
    convert_urls: false,
    document_base_url: location.origin + location.pathname.split('admin')[0],

    content_css: 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.1.3/css/bootstrap.min.css',
    external_filemanager_path: "../tinymce/filemanager/",
    filemanager_title: "Gestionnaire d'image",
    external_plugins: { "filemanager": "filemanager/plugin.min.js" }
});
//relative_urls et remove_script_host: De base est a false, true pour document_base_url
//document_base_url: Permet de faire le maillage interne directement en 'dev' avant livraison.

//Controlle les champs obligatoire et scroll (Ex:Coordonnées).
function loader() {
    if (($(".invalid-feedback").length > 0)) {
        $('.invalid-feedback').parent().parent().parent().parent().parent().parent().parent(".field-form_panel").addClass('ouvert');
        $('html, body').animate({
            scrollTop: $(".invalid-feedback").offset().top - 50
        }, 500);
    }
    if (document.getElementById('calendar')) {
        // Sélectionnez l'élément contenant le calendrier
        var calendarEl = document.getElementById('calendar');
        var rdvData = calendarEl.getAttribute('data-rdv');
        // Convertir la chaîne JSON en un tableau d'objets JavaScript
        var eventsData = JSON.parse(rdvData);

        // Initialisez FullCalendar avec les plugins
        var calendar = new Calendar(calendarEl, {
            plugins: [dayGridPlugin, timeGridPlugin, listPlugin],
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek,timeGridDay,listMonth'
            },
            initialView: 'dayGridMonth',
            locale: frLocale,
            timeZone: 'Europe/Paris',
            height: 750,
            events: eventsData,
            eventClick: handleEventClick,
        });

        // Affichez le calendrier
        calendar.render();
        function handleEventClick(event) {
            if (event.url) {
                window.location.href = event.url;
            }
        }
    }
}
window.onload = loader;

$(document).ready(function () {
    var optionField = $('.js-option-field');
    var circleMarkerField = $('.opt-circle-marker');
    var circleField = $('.opt-circle');
    var polygonField = $('.opt-polygon');

    function showHideFields() {
        var selectedOption = optionField.val();

        if (selectedOption === 'circle' || selectedOption === 'marker') {
            circleMarkerField.show();
        } else {
            circleMarkerField.hide();
        }

        if (selectedOption === 'circle') {
            circleField.show();
        } else {
            circleField.hide();
        }

        if (selectedOption === 'polygon') {
            polygonField.show();
        } else {
            polygonField.hide();
        }
    }

    // Appel initial pour masquer les champs selon la valeur sélectionnée
    showHideFields();

    // Ajoutez un gestionnaire d'événements pour écouter les changements de valeur
    optionField.on('change', showHideFields);
});

// Ajout du compteur de caractères et de longueur en pixels pour les champs meta
$(document).ready(function () {
    // Fonction pour mesurer la largeur d'un texte en pixels
    function getTextWidth(text, font) {
        const canvas = document.createElement("canvas");
        const context = canvas.getContext("2d");
        context.font = font;
        return context.measureText(text).width;
    }

    // Met à jour le compteur de caractères et la longueur en pixels
    function updateMetaCount(inputElement, counterElement, maxLength, pxElement) {
        const text = inputElement.value;
        const charCount = text.length;
        const maxLengthDisplay = maxLength ? `/ ${maxLength}` : '';
        const font = window.getComputedStyle(inputElement).font;
        const textWidth = getTextWidth(text, font);

        // Mise à jour du contenu du compteur
        counterElement.innerHTML = `${charCount}/${maxLength} caractères | ${Math.round(textWidth)} px`;

        // Changer la couleur en fonction du dépassement
        if (charCount > maxLength) { // Exemple : Limite de 600 px pour la largeur du texte
            counterElement.style.color = 'red';  // Rouge si dépassement
        } else {
            counterElement.style.color = 'green'; // Vert si dans les limites
        }
    }

    // Initialisation pour le champ Meta Title
    const metaTitleInput = document.querySelector('.meta-title .form-widget input');
    const metaTitleCounter = document.createElement('div');
    metaTitleCounter.classList.add('meta-counter');
    metaTitleInput.parentNode.appendChild(metaTitleCounter);

    // Mettre à jour à chaque changement de texte
    metaTitleInput.addEventListener('input', function () {
        updateMetaCount(metaTitleInput, metaTitleCounter, 60); // 60 est la limite du nombre de caractères
    });

    // Initialisation pour le champ Meta Description
    const metaDescInput = document.querySelector('.meta-description .form-widget input');
    const metaDescCounter = document.createElement('div');
    metaDescCounter.classList.add('meta-counter');
    metaDescInput.parentNode.appendChild(metaDescCounter);

    // Mettre à jour à chaque changement de texte
    metaDescInput.addEventListener('input', function () {
        updateMetaCount(metaDescInput, metaDescCounter, 160); // 160 est la limite du nombre de caractères pour Meta Description
    });
});
