import AustraliaPostServices from './components/fieldtypes/AustraliaPostServices.vue';

Statamic.booting(() => {
    Statamic.$components.register('australia_post_services-fieldtype', AustraliaPostServices);
});
