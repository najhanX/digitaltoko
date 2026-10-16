import { Link } from "@inertiajs/react";
import { ArrowRight, Gamepad2 } from "lucide-react";
import GameArt from "./GameArt";
import {
    FilterBar,
    Pagination,
    useServerList,
    type ListFilters,
    type PageMeta,
} from "./ListControls";

export type StoreCategory = {
    id: string;
    name: string;
    service: string;
    image: string | null;
    products_count: number;
};
export default function NebulaCategories({
    items,
    filters,
    pagination,
    title,
    preview = false,
}: {
    items: StoreCategory[];
    filters: ListFilters;
    pagination: PageMeta;
    title?: string;
    preview?: boolean;
}) {
    const list = useServerList(filters, [
        "categoryCards",
        "categoryPagination",
        "filters",
    ]);
    return (
        <section className="sf-catalog ng-category-catalog" id="catalog">
            <div className="ng-section-heading">
                <div>
                    <span className="ng-eyebrow">
                        PILIH GAME. LANJUT BERMAIN.
                    </span>
                    <h2>{title || "Semua Produk Digital"}</h2>
                    <p>
                        Pilih kategori, lalu tentukan produk dan nominal top up
                        Anda.
                    </p>
                </div>
                <Gamepad2 size={26} />
            </div>
            {!preview && (
                <FilterBar
                    storefront
                    filters={filters}
                    onApply={list.apply}
                    busy={list.busy}
                    placeholder="Cari game atau voucher…"
                />
            )}
            <Pagination
                compact
                meta={pagination}
                onPage={list.go}
                busy={list.busy}
                label="Navigasi atas kategori"
            />
            {items.length ? (
                <div className="ng-categories-grid">
                    {items.map((item, index) => (
                        <Link
                            className="ng-category-card"
                            key={item.id}
                            href={
                                preview
                                    ? "/preview/storefront?template=nebula&page=order"
                                    : "/kategori/" + item.id
                            }
                        >
                            <GameArt
                                name={item.name}
                                image={item.image}
                                index={index}
                            />
                            <div>
                                <small>{item.service}</small>
                                <h3>{item.name}</h3>
                                <span>
                                    {item.products_count} pilihan
                                    <ArrowRight size={16} />
                                </span>
                            </div>
                        </Link>
                    ))}
                </div>
            ) : (
                <div className="sf-empty">
                    <Gamepad2 size={32} />
                    <h3>Kategori belum tersedia</h3>
                    <p>
                        Coba kata kunci lain atau kunjungi kembali katalog toko.
                    </p>
                </div>
            )}
            <Pagination
                meta={pagination}
                onPage={list.go}
                busy={list.busy}
                label="Halaman kategori"
            />
        </section>
    );
}
