/**
 * Channels — ads a shop may add to its screens: above the stores every channel (the platform's,
 * offered to every shop, and each store's own); inside a store, that store's own channels.
 *
 * A plain CRUD table: a channel itself is only a name, how many of its ads play each
 * time, and whether it is on the air. Its ads are managed on the channel's own page
 * (channel-ads.js), because they are files — uploads, measuring, reordering — and a
 * modal is the wrong place for a list of those.
 */
import { createCrudTable } from '../core/crud-table-base.js';
import { validate, required, maxLen } from '../core/validate.js';

const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;

export function registerChannelsTable(Alpine) {
    Alpine.data('channelsTable', (config = {}) => {
        const maxAdsPerPass = config.maxAdsPerPass ?? 50;

        return createCrudTable({
            fetchUrl: '/channels/data',
            dataKey: 'channels',
            entityLabel: 'channel',
            formModalName: 'channel-form-modal',
            deleteModalName: 'confirm-channel-deletion',
            deleteNeedsPassword: true,
            defaultForm: { name: '', ads_per_pass: '', is_active: true },

            extraState: { maxAdsPerPass },

            /* Mirrors ChannelRequest. The server still decides — uniqueness is its alone. */
            validateForm: (form) => {
                const errors = validate(form, {
                    name: [required('Name'), maxLen('Name', 120)],
                });

                const perPass = String(form.ads_per_pass ?? '').trim();
                if (perPass !== '' && (! /^\d+$/.test(perPass) || Number(perPass) < 1 || Number(perPass) > maxAdsPerPass)) {
                    errors.ads_per_pass = [`Enter a number from 1 to ${maxAdsPerPass}, or leave it blank to play every ad.`];
                }

                return errors;
            },

            mapItemToForm: (channel) => ({
                name: channel.name,
                ads_per_pass: channel.ads_per_pass ?? '',
                is_active: !! channel.is_active,
            }),

            extraMethods: {
                /** "5 ads · 3 running today" — an ad outside its dates is on no screen. */
                adsLabel(channel) {
                    const total = channel.ads_count ?? 0;
                    if (total === 0) return 'No ads yet';

                    const running = channel.running_ads_count ?? 0;

                    return running === total
                        ? plural(total, 'ad')
                        : `${plural(total, 'ad')} · ${running} running today`;
                },

                perPassLabel(channel) {
                    return channel.ads_per_pass ? `${plural(channel.ads_per_pass, 'ad')} each time` : 'Every ad';
                },

                usageLabel(channel) {
                    const screens = channel?.screens_count ?? 0;
                    if (screens === 0) return 'No screens yet';

                    // A store's own channel only ever plays in that store.
                    return channel.store_name
                        ? plural(screens, 'screen')
                        : `${plural(screens, 'screen')} in ${plural(channel.stores_count ?? 0, 'shop')}`;
                },
            },
        })();
    });
}
