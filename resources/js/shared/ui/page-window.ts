// Which page numbers a pagination control shows: always the first and
// last, the current page with `siblings` on each side, and gaps (null)
// where pages are skipped. A gap never hides a single page, and when every
// page fits in the control's usual width they are all shown.
export function pageWindow(current: number, last: number, siblings = 1): (number | null)[] {
    if (last <= 1) return [1];

    if (last <= siblings * 2 + 5) return Array.from({ length: last }, (_, i) => i + 1);

    const page = Math.min(Math.max(current, 1), last);
    const from = Math.max(2, page - siblings);
    const to = Math.min(last - 1, page + siblings);
    const pages: (number | null)[] = [1];

    if (from > 3) pages.push(null);
    else for (let p = 2; p < from; p++) pages.push(p);

    for (let p = from; p <= to; p++) pages.push(p);

    if (to < last - 2) pages.push(null);
    else for (let p = to + 1; p < last; p++) pages.push(p);

    pages.push(last);

    return pages;
}
