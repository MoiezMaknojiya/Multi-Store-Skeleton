/**
 * Activity log table Alpine component — a read-only audit trail built on the
 * shared CRUD table (fetch/search/pagination), plus the yearly storage panel
 * (partition status + one-click maintenance) that a platform role holding
 * activity-destroy sees.
 */
import axios from 'axios';
import { createCrudTable } from '../core/crud-table-base.js';

/* Local-timezone YYYY-MM-DD (toISOString would shift the day near midnight). */
const isoDate = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const daysAgo = (n) => { const d = new Date(); d.setDate(d.getDate() - n); return isoDate(d); };

export function registerActivityTable(Alpine) {
    Alpine.data('activityTable', (config = {}) => createCrudTable({
        fetchUrl: '/activity/data',
        dataKey: 'logs',
        entityLabel: 'activity entry',
        extraState: {
            /* Whether this viewer holds activity-destroy — the storage panel is theirs. */
            canMaintain: config.canMaintain ?? false,
            partitions: [],
            maintaining: false,
            /* Date range: every listing/search is bounded by it so MySQL only
             * scans the matching yearly partitions. Default: last 30 days. */
            preset: '30d',
            from: daysAgo(30),
            to: isoDate(new Date()),
        },

        extraMethods: {
            onInit() {
                if (this.canMaintain) this.fetchPartitions();
            },

            /* Send the range as real UTC INSTANTS derived from the viewer's LOCAL
             * day boundaries. This is what makes the window correct in every
             * timezone (Pakistan, US, Canada…) AND guarantees a just-created log
             * is never hidden: local end-of-day, expressed in UTC, is always at or
             * after "now", so fresh rows stamped in server-UTC still fall inside. */
            extraParams() {
                const params = {};
                if (this.from) params.from = new Date(`${this.from}T00:00:00`).toISOString();
                if (this.to) params.to = new Date(`${this.to}T23:59:59.999`).toISOString();
                return params;
            },

            applyPreset(preset) {
                this.preset = preset;
                const today = isoDate(new Date());
                if (preset === 'today') { this.from = today; this.to = today; }
                else if (preset === '7d') { this.from = daysAgo(7); this.to = today; }
                else if (preset === '30d') { this.from = daysAgo(30); this.to = today; }
                else if (preset === 'year') { this.from = `${new Date().getFullYear()}-01-01`; this.to = today; }
                else return; // 'custom': keep the dates, the user edits them directly
                this.currentPage = 1;
                this.fetchItems();
            },

            onDateChange() {
                this.preset = 'custom';
                this.currentPage = 1;
                this.fetchItems();
            },

            async fetchPartitions() {
                try {
                    const res = await axios.get('/activity/partitions');
                    this.partitions = res.data.partitions;
                } catch (error) {
                    console.error('Failed to fetch partition status:', error);
                    this.partitions = [];
                }
            },

            async runMaintenance() {
                if (this.maintaining) return;
                this.maintaining = true;
                try {
                    const res = await axios.post('/activity/partitions/maintain');
                    this.$dispatch('close-modal', 'confirm-activity-maintenance');
                    const dropped = res.data.dropped ?? [];
                    const created = res.data.created ?? [];
                    const parts = [];
                    if (created.length) parts.push(`opened: ${created.join(', ')}`);
                    if (dropped.length) parts.push(`deleted: ${dropped.map(d => `${d.year} (${d.rows} rows)`).join(', ')}`);
                    window.toast(`Maintenance complete — ${parts.length ? parts.join('; ') : 'nothing to do'}`, 'success');
                    await this.fetchPartitions();
                    await this.fetchItems();
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Maintenance failed. Please try again.');
                } finally {
                    this.maintaining = false;
                }
            },
        },
    })());
}
