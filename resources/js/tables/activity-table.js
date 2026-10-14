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
/* A day of the calendar as a date box writes it: "2026-13-01" would make the range unreadable (toISOString throws, so
 * the log could not load, again on every reload), and "2026-02-30" would quietly become the 2nd of March. */
const isCalendarDay = (value) => /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(value) && isoDate(new Date(`${value}T00:00:00`)) === value;

export function registerActivityTable(Alpine) {
    Alpine.data('activityTable', (config = {}) => createCrudTable({
        fetchUrl: '/activity/data',
        dataKey: 'logs',
        entityLabel: 'activity entry',
        addressKeys: { from: ['from', isCalendarDay], to: ['to', isCalendarDay] },
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
            /* A range that ends before it starts: said under To, and nothing is asked of the server. */
            rangeError: '',
        },

        extraMethods: {
            onInit() {
                if (this.canMaintain) this.fetchPartitions();
                // Dates the address brought (crud-table-base) are somebody's own range.
                if (this.from !== daysAgo(30) || this.to !== isoDate(new Date())) this.preset = 'custom';
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

            /** "Sep 30, 2026, 9:05 AM" — a date and a time, no seconds. */
            whenLabel(value) {
                return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—';
            },

            /** "member.role_changed" → "Member role changed". */
            actionLabel(action) {
                const words = String(action ?? '').replace(/[._]+/g, ' ').trim();

                return words ? words.charAt(0).toUpperCase() + words.slice(1) : '—';
            },

            /** Red for what was taken away, green for what was added, blue for a change, grey for the rest (a sign-in,
             *  maintenance). Taken away is asked first: "unpublished" ends in "published". */
            actionBadge(action) {
                const verb = String(action ?? '').split('.').pop();

                if (/(deleted|removed|revoked|left|unpublished|pruned|declined|discarded|paused)$/.test(verb)) return 'badge-danger';
                if (/(created|added|invited|accepted|registered|uploaded|published|paired|assigned|duplicated|installed|verified|resumed)$/.test(verb)) return 'badge-success';
                if (/(updated|changed|reordered|resent|recompiled|reset|switched)$/.test(verb)) return 'badge-info';

                return 'badge-neutral';
            },

            applyPreset(preset) {
                this.preset = preset;
                const today = isoDate(new Date());
                if (preset === 'today') { this.from = today; this.to = today; }
                else if (preset === '7d') { this.from = daysAgo(7); this.to = today; }
                else if (preset === '30d') { this.from = daysAgo(30); this.to = today; }
                else if (preset === 'year') { this.from = `${new Date().getFullYear()}-01-01`; this.to = today; }
                else return; // 'custom': keep the dates, the user edits them directly
                this.rangeError = '';
                this.currentPage = 1;
                this.fetchItems();
            },

            onDateChange() {
                this.preset = 'custom';
                this.rangeError = this.from && this.to && this.to < this.from ? 'The end date cannot be before the start date.' : '';
                if (this.rangeError) return;
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
                    await this.refreshInPlace();
                } catch (error) {
                    window.toast(error.response?.data?.message ?? 'Maintenance failed. Please try again.');
                } finally {
                    this.maintaining = false;
                }
            },
        },
    })());
}
