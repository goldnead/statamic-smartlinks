import { inertia } from '@statamic/cms/api';

import SmartlinksIndex from './pages/Smartlinks/Index.vue';
import SmartlinkUrlFieldtype from './fieldtypes/SmartlinkUrl.vue';

/*
 * The `smartlinks::` prefix keeps this page out of core's names and every
 * other addon's. Registered inside Statamic.booting, because `inertia` and
 * the component registry belong to the CP runtime this bundle is
 * externalised against.
 */
Statamic.booting(() => {
    inertia.register('smartlinks::Smartlinks/Index', SmartlinksIndex);
    Statamic.$components.register('smartlink_url-fieldtype', SmartlinkUrlFieldtype);
});
