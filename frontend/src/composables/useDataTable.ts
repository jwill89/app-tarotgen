import { ref, computed, toValue, type MaybeRefOrGetter } from 'vue'

/**
 * Lightweight client-side table search + column sort. Reactive, dependency-free
 * and Vue-native — a better fit here than a jQuery grid (DataTables) or a
 * spreadsheet component (Handsontable) given the small, already-client-side data.
 */

export type SortDir = 'asc' | 'desc'

export interface DataTableOptions<T> {
  /** Text searched by the search box (concatenate the relevant fields). */
  searchText?: (row: T) => string
  /** Per-column value accessors used when sorting. */
  sortAccessors?: Record<string, (row: T) => string | number>
  initialSort?: string
  initialDir?: SortDir
}

export function useDataTable<T>(source: MaybeRefOrGetter<T[]>, options: DataTableOptions<T> = {}) {
  const search = ref('')
  const sortKey = ref<string | null>(options.initialSort ?? null)
  const sortDir = ref<SortDir>(options.initialDir ?? 'asc')

  function toggleSort(key: string): void {
    if (sortKey.value === key) {
      sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
    } else {
      sortKey.value = key
      sortDir.value = 'asc'
    }
  }

  /** FontAwesome icon name reflecting this column's current sort state. */
  function headerIcon(key: string): string {
    if (sortKey.value !== key) {
      return 'fa-sort'
    }
    return sortDir.value === 'asc' ? 'fa-sort-up' : 'fa-sort-down'
  }

  const rows = computed<T[]>(() => {
    let list = [...toValue(source)]

    const q = search.value.trim().toLowerCase()
    if (q && options.searchText) {
      const searchText = options.searchText
      list = list.filter((row) => searchText(row).toLowerCase().includes(q))
    }

    const key = sortKey.value
    const accessor = key && options.sortAccessors ? options.sortAccessors[key] : undefined
    if (accessor) {
      const dir = sortDir.value === 'asc' ? 1 : -1
      list.sort((a, b) => {
        const av = accessor(a)
        const bv = accessor(b)
        if (typeof av === 'number' && typeof bv === 'number') {
          return (av - bv) * dir
        }
        return String(av).localeCompare(String(bv), undefined, { numeric: true }) * dir
      })
    }

    return list
  })

  return { search, sortKey, sortDir, rows, toggleSort, headerIcon }
}
