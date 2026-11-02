import PostpaidMappings, {type PostpaidPair} from '../../components/PostpaidMappings';
import CatalogHelp from '../../components/CatalogHelp';
import { Link, useForm, usePage } from "@inertiajs/react";
import { useEffect, useState } from "react";
import {
    Plus,
    Pencil,
    Trash2,

    Package,
    Power,
    PowerOff,
} from "lucide-react";
import TaxonomySelect from "../../components/TaxonomySelect";
import WorkspaceLayout from "../../components/WorkspaceLayout";
import Modal from "../../components/Modal";
import ConfirmDialog from "../../components/ConfirmDialog";
import RupiahInput from "../../components/RupiahInput";
import PricingTargetInput from "../../components/PricingTargetInput";
import PricingSettings, {
    inheritedMarkupLabel,
    inheritedUpdateLabel,
    type MarkupMode,
} from "../../components/PricingSettings";
import {
    FilterBar,
    Pagination,
    useServerList,
    type PageMeta,
    type ListFilters,
} from "../../components/ListControls";
import {
    CatalogPicker,
    FormErrors,
    rupiah,
    sourceLabels,
    kindLabels,
    type CatalogStore,
    type MappingOption,
} from "../../components/CatalogForms";
type Product = {
    id: string;
    code: string;
    name: string;
    description: string | null;
    kind: string;
    service: string;
    category: string;
    service_id: string | null;
    category_id: string | null;
    price: number;
    postpaid_commission?: number|null;
    postpaid_fees?: {service_admin:number;store_admin:number}|null;
    sale_price: number;
    active: boolean;
    available: boolean;
    supplier_stale: boolean;
    quantity: number | null;
    markup: string | null;
    auto_update: boolean | null;
    pricing_basis: "cheapest" | "highest";
    use_stock: boolean;
    mapping_details: MappingOption[];
    price_blocked: boolean;
    cost: number | null;
    rule: { markup: string; auto_update: boolean; level: string };
};
type PricingRule = {
    id: string;
    scope: string;
    key: string;
    markup: string | null;
    auto_update: boolean | null;
    pricing_basis: "cheapest" | "highest" | null;
};
type Props = {
    selectedCategory?: {
        id: string;
        name: string;
        service: { id: string; name: string };
    } | null;
    role: "owner" | "staff";
    store: CatalogStore;
    products: Product[];
    canManage: boolean;
    canPrice: boolean;
    canStock: boolean;
    filters: ListFilters;
    pagination: PageMeta;
    rules: PricingRule[];
    ruleFilters: ListFilters;
    rulePagination: PageMeta;
};
const tri = (value: boolean | null) =>
    value === null ? "inherit" : value ? "on" : "off";
const autoValue = (value: string) =>
    value === "inherit" ? null : value === "on";
export default function Catalog({
    selectedCategory,
    role,
    store,
    products,
    canManage,
    canPrice,
    canStock,
    filters,
    pagination,
    rules,
    ruleFilters,
    rulePagination,
}: Props) {
    const base = "/stores/" + store.id;
    const tab =
        new URLSearchParams(usePage().url.split("?")[1] ?? "").get("tab") ===
        "prices"
            ? "prices"
            : "products";
    const list = useServerList(filters, ["products", "filters", "pagination"]),
        ruleList = useServerList(
            ruleFilters,
            ["rules", "ruleFilters", "rulePagination"],
            "rules_",
        );
    const [productOpen, setProductOpen] = useState(false),
        [editing, setEditing] = useState<Product | null>(null),
        [mappings, setMappings] = useState<MappingOption[]>([]);
    const [ruleOpen, setRuleOpen] = useState(false),
        [editingRule, setEditingRule] = useState<PricingRule | null>(null),
        [removing, setRemoving] = useState<{
            id: string;
            type: "product" | "rule";
            name: string;
        } | null>(null);
    const [selected, setSelected] = useState<string[]>([]),
        [bulkAction, setBulkAction] = useState<{
            action: "activate" | "deactivate" | "delete";
            ids: string[];
            name?: string;
        } | null>(null);
    const bulk = useForm({ action: "activate", ids: [] as string[] });
    useEffect(
        () => setSelected([]),
        [
            store.id,
            pagination.current_page,
            JSON.stringify(filters),
            products.map((item) => item.id).join(","),
            tab,
        ],
    );
    function prepareBulk(
        action: "activate" | "deactivate" | "delete",
        ids: string[],
        name?: string,
    ) {
        bulk.clearErrors();
        setBulkAction({ action, ids: [...ids], name });
    }
    const product = useForm({
        code: "",
        name: "",
        description: "",
        kind: "ppob",
        service: "",
        category: "",
        service_id: "",
        category_id: "",
        active: true,
        sale_price: "",
        markup: "",
        auto_update: "inherit",
        mapping_ids: [] as string[],
        postpaid_pairs: {} as Record<string,PostpaidPair>,
        use_stock: false,
    });
    const rule = useForm({
            scope: "category",
            key: "",
            markup: "",
            auto_update: "inherit",
            pricing_basis: "inherit" as "inherit" | "cheapest" | "highest",
        }),
        deletion = useForm({});
    const [productTab, setProductTab] = useState<
        "identity" | "price" | "sources"
    >("identity");
    const [productMarkupMode, setProductMarkupMode] =
            useState<MarkupMode>("inherit"),
        [ruleMarkupMode, setRuleMarkupMode] = useState<MarkupMode>("inherit");
    function openProduct(item: Product | null) {
        setProductTab(canManage ? "identity" : "price");
        setProductMarkupMode(
            item?.markup !== null && item?.markup !== undefined
                ? "own"
                : "inherit",
        );
        setEditing(item);
        setMappings(item?.mapping_details ?? []);
        product.clearErrors();
        product.setData({
            code: item?.code ?? "",
            name: item?.name ?? "",
            description: item?.description ?? "",
            kind: item?.kind ?? "ppob",
            service: item?.service ?? selectedCategory?.service.name ?? "",
            category: item?.category ?? selectedCategory?.name ?? "",
            service_id: item?.service_id ?? selectedCategory?.service.id ?? "",
            category_id: item?.category_id ?? selectedCategory?.id ?? "",
            active: item?.active ?? true,
            sale_price: item ? String(item.price) : "",
            markup: item?.markup ?? "",
            auto_update: tri(item?.auto_update ?? null),
            mapping_ids: item?.mapping_details.map((value) => value.id) ?? [],
            use_stock: item?.use_stock ?? false,
            postpaid_pairs: Object.fromEntries((item?.mapping_details??[]).filter(value=>value.postpaid).map(value=>[value.id,{inquiry_id:value.postpaid!.inquiry_id,admin:String(value.postpaid!.admin),inquiry:value.inquiry_item?{...value,...value.inquiry_item}:undefined}])),
        });
        setProductOpen(true);
    }
    function closeProduct() {
        setProductOpen(false);
        setEditing(null);
        setMappings([]);
        product.reset();
        product.clearErrors();
    }
    function openRule(item: PricingRule | null) {
        setRuleMarkupMode(
            item?.markup !== null && item?.markup !== undefined
                ? "own"
                : "inherit",
        );
        setEditingRule(item);
        rule.clearErrors();
        rule.setData({
            scope: item?.scope ?? "category",
            key: item?.key ?? "",
            markup: item?.markup ?? "",
            auto_update: tri(item?.auto_update ?? null),
            pricing_basis: item?.pricing_basis ?? "inherit",
        });
        setRuleOpen(true);
    }
    function toggleMapping(option: MappingOption) {
        setMappings((current) =>
            current.some((item) => item.id === option.id)
                ? current.filter((item) => item.id !== option.id)
                : [...current, option],
        );
    }
    function submitProduct() {
        if (canManage && !product.data.category_id) {
            product.setError(
                "category_id",
                "Pilih layanan dan kategori utama terlebih dahulu.",
            );
            setProductTab("identity");
            return;
        }
        if (
            canPrice &&
            productMarkupMode === "own" &&
            !product.data.markup.trim()
        ) {
            product.setError(
                "markup",
                "Isi nilai markup sendiri, atau pilih mengikuti markup kategori / layanan.",
            );
            setProductTab("price");
            return;
        }
        product.transform((data) => {
            const price = {
                sale_price:
                    data.sale_price === "" ? null : Number(data.sale_price),
                markup: productMarkupMode === "own" ? data.markup.trim() : null,
                auto_update: autoValue(data.auto_update),
            };
            if (!canManage) return price;
            const {
                sale_price: _sale,
                markup: _markup,
                auto_update: _auto,
                ...details
            } = data;
            return {
                ...details,
                mapping_ids: mappings.map((item) => item.id),
                postpaid_pairs: data.kind==='postpaid'?Object.fromEntries(mappings.filter(item=>item.source==='otomax').map(item=>[item.id,{inquiry_id:data.postpaid_pairs[item.id]?.inquiry_id??'',admin:data.postpaid_pairs[item.id]?.admin===''?null:Number(data.postpaid_pairs[item.id]?.admin??'')}])):{},
                ...(canPrice ? price : {}),
            };
        });
        const options = {
            preserveScroll: true,
            onSuccess: closeProduct,
            onError: (errors: Record<string, string>) => {
                const keys = Object.keys(errors);
                setProductTab(
                    keys.some((key) =>
                        ["sale_price", "markup", "auto_update", "pricing_basis"].includes(key),
                    )
                        ? "price"
                        : keys.some(
                                (key) =>
                                    key.startsWith("mapping_ids") || key.startsWith("postpaid_pairs") ||
                                    key === "use_stock",
                            )
                          ? "sources"
                          : canManage
                            ? "identity"
                            : "price",
                );
            },
        };
        if (editing)
            product.patch(
                base + "/products/" + editing.id + (!canManage ? "/price" : ""),
                options,
            );
        else product.post(base + "/products", options);
    }
    return (
        <WorkspaceLayout
            contentClassName="catalog-workspace"
            role={role}
            title="Katalog & harga"
            description={
                "Kelola produk dan harga jual untuk web serta bot " + store.name + "."
            }
            actions={
                <>
                    {tab === "products" && canManage && (
                        <button
                            className="button"
                            type="button"
                            onClick={() => openProduct(null)}
                        >
                            <Plus size={16} />
                            Tambah produk
                        </button>
                    )}
                    {tab === "prices" && canPrice && (
                        <button
                            className="button"
                            type="button"
                            onClick={() => openRule(null)}
                        >
                            <Plus size={16} />
                            Tambah aturan
                        </button>
                    )}
                </>
            }
        >
            <nav className="content-tabs" aria-label="Katalog toko">
                <Link
                    href={base + "/products"}
                    className={tab === "products" ? "selected" : ""}
                    aria-current={tab === "products" ? "page" : undefined}
                >
                    Produk toko
                </Link>
                <Link
                    href={base + "/products?tab=prices"}
                    className={tab === "prices" ? "selected" : ""}
                    aria-current={tab === "prices" ? "page" : undefined}
                >
                    Aturan harga
                </Link>
            </nav>
            <CatalogHelp title={tab === "products" ? "Cara kerja sumber dan harga produk" : "Prioritas aturan: produk → kategori → layanan"}>
                <p>
                    {tab === "products"
                        ? "Harga jual mengikuti dasar markup terpilih. Transaksi mencoba sumber termurah dahulu. Produk tidak tersedia jika modal melebihi harga jual; perbarui harga atau aktifkan pembaruan otomatis."
                        : "Markup dan pembaruan otomatis masing-masing mengikuti produk → kategori → layanan. Tanpa aturan: markup 0, pembaruan nonaktif."}
                </p>
            </CatalogHelp>
            {selectedCategory && (
                <div className="summary-note admin-owner-filter">
                    <span>
                        {selectedCategory.service.name} →{" "}
                        <strong>{selectedCategory.name}</strong>
                    </span>
                    <Link className="text-link" href={base + "/products"}>
                        Semua produk
                    </Link>
                </div>
            )}
            {tab === "products" ? (
                <section className="table-card data-list-table catalog-data-card">
                    <header className="data-table-header">
                        <h2>Produk toko</h2>
                        <Pagination
                            compact
                            meta={pagination}
                            onPage={list.go}
                            busy={list.busy}
                        />
                    </header>
                    <FilterBar
                        filters={filters}
                        onApply={list.apply}
                        busy={list.busy}
                        placeholder="Cari kode, produk, kategori, atau layanan…"
                        choices={[
                            {
                                key: "kind",
                                label: "Jenis produk",
                                options: kindLabels,
                            },
                            {
                                key: "active",
                                label: "Status",
                                options: { "1": "Aktif", "0": "Nonaktif" },
                            },
                        ]}
                    />
                    {canManage && selected.length > 0 && (
                        <div className="catalog-selection-toolbar">
                            <span>
                                <strong>{selected.length}</strong> produk
                                dipilih di halaman ini
                            </span>
                            <div className="row-actions">
                                <button
                                    type="button"
                                    className="button secondary small"
                                    disabled={list.busy || bulk.processing}
                                    onClick={() =>
                                        prepareBulk("activate", selected)
                                    }
                                >
                                    <Power size={14} />
                                    Aktifkan
                                </button>
                                <button
                                    type="button"
                                    className="button secondary small"
                                    disabled={list.busy || bulk.processing}
                                    onClick={() =>
                                        prepareBulk("deactivate", selected)
                                    }
                                >
                                    <PowerOff size={14} />
                                    Nonaktifkan
                                </button>
                                <button
                                    type="button"
                                    className="button secondary small danger-text"
                                    disabled={list.busy || bulk.processing}
                                    onClick={() =>
                                        prepareBulk("delete", selected)
                                    }
                                >
                                    <Trash2 size={14} />
                                    Hapus
                                </button>
                                <button
                                    type="button"
                                    className="button secondary small"
                                    onClick={() => setSelected([])}
                                >
                                    Batal pilih
                                </button>
                            </div>
                        </div>
                    )}
                    <div className="table-scroll catalog-products-table">
                        <table>
                            <thead>
                                <tr>
                                    {canManage && (
                                        <th className="catalog-selection-cell">
                                            <input
                                                type="checkbox"
                                                aria-label="Pilih semua produk di halaman ini"
                                                disabled={
                                                    !products.length ||
                                                    list.busy ||
                                                    bulk.processing
                                                }
                                                checked={
                                                    products.length > 0 &&
                                                    selected.length ===
                                                        products.length
                                                }
                                                ref={(element) => {
                                                    if (element)
                                                        element.indeterminate =
                                                            selected.length >
                                                                0 &&
                                                            selected.length <
                                                                products.length;
                                                }}
                                                onChange={(event) =>
                                                    setSelected(
                                                        event.target.checked
                                                            ? products.map(
                                                                  (item) =>
                                                                      item.id,
                                                              )
                                                            : [],
                                                    )
                                                }
                                            />
                                        </th>
                                    )}
                                    <th>Produk</th>
                                    <th>Layanan / kategori</th>
                                    <th>Harga jual</th>
                                    <th>Ketersediaan</th>
                                    <th>Parsing</th>
                                    <th>Tindakan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {products.map((item) => (
                                    <tr
                                        key={item.id}
                                        className={
                                            selected.includes(item.id)
                                                ? "catalog-row-selected"
                                                : ""
                                        }
                                    >
                                        {canManage && (
                                            <td className="catalog-selection-cell table-cell-center">
                                                <input
                                                    type="checkbox"
                                                    aria-label={
                                                        "Pilih produk " +
                                                        item.name
                                                    }
                                                    disabled={
                                                        list.busy ||
                                                        bulk.processing
                                                    }
                                                    checked={selected.includes(
                                                        item.id,
                                                    )}
                                                    onChange={(event) =>
                                                        setSelected(
                                                            event.target.checked
                                                                ? [
                                                                      ...selected,
                                                                      item.id,
                                                                  ]
                                                                : selected.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          item.id,
                                                                  ),
                                                        )
                                                    }
                                                />
                                            </td>
                                        )}
                                        <td>
                                            <strong>{item.name}</strong>
                                            <small>
                                                {item.code} ·{" "}
                                                {kindLabels[item.kind]}
                                            </small>
                                        </td>
                                        <td>
                                            {item.service}
                                            <small>{item.category}</small>
                                        </td>
                                        <td className="table-cell-center">
                                            {item.kind === "postpaid"
                                                ? "Total admin "
                                                : ""}
                                            {rupiah(item.price)}
                                            {item.postpaid_fees&&<small>Layanan {rupiah(item.postpaid_fees.service_admin)} · Toko {rupiah(item.postpaid_fees.store_admin)}</small>}
                                            <small>
                                                {item.rule.auto_update
                                                    ? "Otomatis · " +
                                                      item.rule.markup
                                                    : "Harga tetap"}
                                            </small>
                                            {item.price_blocked && (
                                                <small className="danger-text">
                                                    Modal melebihi harga jual
                                                </small>
                                            )}
                                        </td>
                                        <td className="table-cell-center">
                                            <span
                                                className={
                                                    "store-status " +
                                                    (item.available
                                                        ? "active"
                                                        : "expired")
                                                }
                                            >
                                                {!item.active
                                                    ? "Nonaktif"
                                                    : item.available
                                                      ? "Tersedia"
                                                      : item.supplier_stale
                                                        ? "Perlu sinkronisasi"
                                                        : "Belum tersedia"}
                                            </span>
                                            <small>
                                                {item.supplier_stale
                                                    ? "Stok belum terverifikasi"
                                                    : item.quantity === null
                                                      ? "Stok pemasok"
                                                      : item.quantity + " stok"}
                                            </small>
                                        </td>
                                        <td>
                                            {item.mapping_details.length +
                                                (item.use_stock ? 1 : 0)}{" "}
                                            sumber
                                            <small>
                                                {[
                                                    ...new Set(
                                                        item.mapping_details.map(
                                                            (mapping) =>
                                                                sourceLabels[
                                                                    mapping
                                                                        .source
                                                                ],
                                                        ),
                                                    ),
                                                    ...(item.use_stock
                                                        ? ["Stok sendiri"]
                                                        : []),
                                                ].join(", ") ||
                                                    "Belum diparsing"}
                                            </small>
                                        </td>
                                        <td className="table-cell-center">
                                            <div className="row-actions catalog-item-actions" role="group" aria-label={"Tindakan produk " + item.name}>
                                                {(canManage || canPrice) && (
                                                    <button
                                                        type="button"
                                                        className="icon-button"
                                                        aria-label={(canManage ? "Edit produk " : "Atur harga ") + item.name}
                                                        title={canManage ? "Edit produk" : "Atur harga"}
                                                        onClick={() =>
                                                            openProduct(item)
                                                        }
                                                    >
                                                        <Pencil size={14} />

                                                    </button>
                                                )}
                                                {canManage && (
                                                    <button
                                                        type="button"
                                                        className="icon-button"
                                                        aria-label={(item.active ? "Nonaktifkan produk " : "Aktifkan produk ") + item.name}
                                                        title={item.active ? "Nonaktifkan produk" : "Aktifkan produk"}
                                                        disabled={
                                                            bulk.processing ||
                                                            list.busy
                                                        }
                                                        onClick={() =>
                                                            prepareBulk(
                                                                item.active
                                                                    ? "deactivate"
                                                                    : "activate",
                                                                [item.id],
                                                                item.name,
                                                            )
                                                        }
                                                    >
                                                        {item.active ? (
                                                            <PowerOff
                                                                size={14}
                                                            />
                                                        ) : (
                                                            <Power size={14} />
                                                        )}
                                                    </button>
                                                )}
                                                {canManage && (
                                                    <button
                                                        type="button"
                                                        className="icon-button danger-text"
                                                        title="Hapus produk"
                                                        aria-label={
                                                            "Hapus produk " +
                                                            item.name
                                                        }
                                                        onClick={() =>
                                                            setRemoving({
                                                                id: item.id,
                                                                type: "product",
                                                                name: item.name,
                                                            })
                                                        }
                                                    >
                                                        <Trash2 size={16} />
                                                    </button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {!products.length && (
                            <div className="empty-state">
                                <Package size={28} />
                                <h3>Belum ada produk yang cocok</h3>
                                <p>
                                    Impor dari sumber pemasok atau tambah produk
                                    stok sendiri.
                                </p>
                            </div>
                        )}
                    </div>
                    <Pagination
                        meta={pagination}
                        onPage={list.go}
                        busy={list.busy}
                    />
                </section>
            ) : (
                <section className="table-card data-list-table catalog-data-card">
                    <header className="data-table-header">
                        <h2>Aturan kategori & layanan</h2>
                        <Pagination
                            compact
                            meta={rulePagination}
                            onPage={ruleList.go}
                            busy={ruleList.busy}
                        />
                    </header>
                    <FilterBar
                        filters={ruleFilters}
                        onApply={ruleList.apply}
                        busy={ruleList.busy}
                        placeholder="Cari nama kategori, layanan, atau markup…"
                        choices={[
                            {
                                key: "scope",
                                label: "Tingkat aturan",
                                options: {
                                    category: "Kategori",
                                    service: "Layanan",
                                },
                            },
                        ]}
                    />
                    <div className="table-scroll">
                        <table>
                            <thead>
                                <tr>
                                    <th>Tingkat</th>
                                    <th>Nama</th>
                                    <th>Markup</th>
                                    <th>Dasar modal</th>
                                    <th>Pembaruan otomatis</th>
                                    <th>Tindakan</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rules.map((item) => (
                                    <tr key={item.id}>
                                        <td>
                                            {item.scope === "category"
                                                ? "Kategori"
                                                : "Layanan"}
                                        </td>
                                        <td>{item.key}</td>
                                        <td className="table-cell-center">
                                            {item.markup ??
                                                inheritedMarkupLabel(
                                                    item.scope,
                                                )}
                                        </td>
                                        <td className="table-cell-center">{item.pricing_basis === "cheapest" ? "Parsing termurah" : item.pricing_basis === "highest" ? "Parsing termahal" : item.scope === "category" ? "Ikuti layanan" : "Bawaan: termahal"}</td>
                                        <td className="table-cell-center">
                                            {item.auto_update === null
                                                ? inheritedUpdateLabel(
                                                      item.scope,
                                                  )
                                                : item.auto_update
                                                  ? "Aktif — modal + markup"
                                                  : "Nonaktif — harga tersimpan"}
                                        </td>
                                        <td className="table-cell-center">
                                            {canPrice && (
                                                <div className="row-actions catalog-item-actions" role="group" aria-label={"Tindakan aturan " + item.key}>
                                                    <button
                                                        className="icon-button"
                                                        aria-label={"Edit aturan " + item.key}
                                                        title="Edit aturan harga"
                                                        type="button"
                                                        onClick={() =>
                                                            openRule(item)
                                                        }
                                                    >
                                                        <Pencil size={14} />
                                                    </button>
                                                    <button
                                                        className="icon-button danger-text"
                                                        title="Hapus aturan harga"
                                                        type="button"
                                                        aria-label={
                                                            "Hapus aturan " +
                                                            item.key
                                                        }
                                                        onClick={() =>
                                                            setRemoving({
                                                                id: item.id,
                                                                type: "rule",
                                                                name: item.key,
                                                            })
                                                        }
                                                    >
                                                        <Trash2 size={16} />
                                                    </button>
                                                </div>
                                            )}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        {!rules.length && (
                            <div className="empty-state">
                                <h3>Belum ada aturan yang cocok</h3>
                                <p>
                                    Tambahkan markup per kategori atau layanan.
                                    Produk tetap dapat memiliki aturan sendiri.
                                </p>
                            </div>
                        )}
                    </div>
                    <Pagination
                        meta={rulePagination}
                        onPage={ruleList.go}
                        busy={ruleList.busy}
                    />
                </section>
            )}
            <Modal
                open={productOpen}
                title={
                    editing
                        ? canManage
                            ? "Edit produk"
                            : "Atur harga produk"
                        : "Tambah produk"
                }
                wide
                onClose={closeProduct}
                busy={product.processing}
                footer={
                    <>
                        <button
                            className="button secondary"
                            type="button"
                            disabled={product.processing}
                            onClick={closeProduct}
                        >
                            Batal
                        </button>
                        <button
                            className="button"
                            type="submit"
                            form="catalog-product-form"
                            disabled={product.processing}
                        >
                            {product.processing
                                ? "Menyimpan…"
                                : "Simpan produk"}
                        </button>
                    </>
                }
            >
                <form
                    id="catalog-product-form"
                    className="form-stack"
                    onSubmit={(event) => {
                        event.preventDefault();
                        submitProduct();
                    }}
                >
                    <FormErrors errors={product.errors} />
                    {canManage && (
                        <div
                            className="modal-editor-tabs"
                            role="group"
                            aria-label="Bagian pengaturan produk"
                        >
                            {(
                                [
                                    ["identity", "Produk"],
                                    ...(canPrice ? [["price", "Harga"]] : []),
                                    ["sources", "Sumber / parsing"],
                                ] as [typeof productTab, string][]
                            ).map(([key, label]) => (
                                <button
                                    key={key}
                                    type="button"
                                    aria-pressed={productTab === key}
                                    className={
                                        productTab === key ? "selected" : ""
                                    }
                                    onClick={() => setProductTab(key)}
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                    )}
                    {canManage && productTab === "identity" && (
                        <>
                            <div className="field-grid">
                                <label>
                                    Kode produk
                                    <input
                                        required
                                        maxLength={50}
                                        value={product.data.code}
                                        onChange={(event) =>
                                            product.setData(
                                                "code",
                                                event.target.value.toUpperCase(),
                                            )
                                        }
                                    />
                                </label>
                                <label>
                                    Nama produk
                                    <input
                                        required
                                        maxLength={150}
                                        value={product.data.name}
                                        onChange={(event) =>
                                            product.setData(
                                                "name",
                                                event.target.value,
                                            )
                                        }
                                    />
                                </label>
                                <label>
                                    Jenis produk
                                    <select
                                        value={product.data.kind}
                                        onChange={(event) => {
                                            product.setData(
                                                "kind",
                                                event.target.value,
                                            );
                                            if (
                                                event.target.value !== "premium"
                                            )
                                                product.setData(
                                                    "use_stock",
                                                    false,
                                                );
                                            setMappings([]);
                                        }}
                                    >
                                        {Object.entries(kindLabels).map(
                                            ([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </label>
                            </div>
<TaxonomySelect
                            storeId={store.id}
                            enabled={productOpen}
                            value={{
                                service_id: product.data.service_id,
                                category_id: product.data.category_id,
                                service: product.data.service,
                                category: product.data.category,
                            }}
                            onChange={(value) => {
                                product.clearErrors("category_id");
                                product.setData({ ...product.data, ...value });
                            }}
                        />
                            <CatalogHelp title="Deskripsi tambahan (opsional)">
                            <label>
                                Deskripsi
                                <textarea
                                    rows={2}
                                    maxLength={3000}
                                    value={product.data.description}
                                    onChange={(event) =>
                                        product.setData(
                                            "description",
                                            event.target.value,
                                        )
                                    }
                                />
                            </label></CatalogHelp>
                            <label className="checkbox-label">
                                <input
                                    type="checkbox"
                                    checked={product.data.active}
                                    onChange={(event) =>
                                        product.setData(
                                            "active",
                                            event.target.checked,
                                        )
                                    }
                                />
                                Aktifkan produk
                            </label>
                        </>
                    )}
                    {canPrice && productTab === "price" && (
                        <section className="catalog-form-section">
                            <h3>Harga jual</h3>
                            <p>
                                Harga tetap digunakan saat pembaruan otomatis nonaktif. Kosongkan pada produk baru untuk menghitung dari markup.
                            </p>
                            <div className="field-grid">
                                <label>
                                    {product.data.kind === "postpaid"
                                        ? "Total biaya admin (layanan + toko)"
                                        : "Harga tetap"}
                                    <RupiahInput
                                        value={product.data.sale_price}
                                        onChange={(value) =>
                                            product.setData("sale_price", value)
                                        }
                                        min={0}
                                        max={1000000000000}
                                        required={!canManage}
                                    />
                                </label>
                            </div>
                            <p className="field-help">Dasar modal: kategori → layanan → parsing termahal. Ubah bersama di tab Aturan harga.</p>
                            <PricingSettings
                                postpaid={product.data.kind === "postpaid"}
                                scope="product"
                                mode={productMarkupMode}
                                markup={product.data.markup}
                                autoUpdate={product.data.auto_update}
                                onMode={(value) => {
                                    setProductMarkupMode(value);
                                    product.clearErrors("markup");
                                }}
                                onMarkup={(value) =>
                                    product.setData("markup", value)
                                }
                                onAutoUpdate={(value) =>
                                    product.setData("auto_update", value)
                                }
                            />
                            {editing?.cost !== null && editing && (
                                <p>
                                    {product.data.kind === "postpaid" ? "Admin layanan:" : "Modal dasar harga tersimpan:"}{" "}
                                    {rupiah(editing.cost!)}.{product.data.kind === "postpaid" && editing.postpaid_commission !== null && editing.postpaid_commission !== undefined && <> Penyesuaian modal sumber: {rupiah(editing.postpaid_commission)}.</>} Markup yang berlaku
                                    sebelum perubahan: {editing.rule.markup}.
                                    Pembaruan otomatis:{" "}
                                    {editing.rule.auto_update
                                        ? "aktif"
                                        : "nonaktif"}
                                    .
                                </p>
                            )}
                            {product.data.kind === "postpaid" && (
                                <p>
                                    Total bayar = tagihan + admin layanan + penyesuaian sumber + markup. Penyesuaian OtomaX negatif mengurangi modal; positif menambah modal. Markup persen memakai besar penyesuaian sumber terpilih. Tagihan baru diketahui setelah cek tagihan.
                                </p>
                            )}
                        </section>
                    )}
                    {canManage && productTab === "sources" && (
                        <section className="catalog-form-section">
                            <h3>Parsing produk</h3>
                            <p>
                                {product.data.kind === 'postpaid' ? 'Pilih produk bayar. Untuk OtomaX, pasangkan kode cek tagihannya pada bagian di bawah.' : 'Pilih hingga 20 sumber sejenis. Sistem mendahulukan modal termurah.'}
                            </p>
                            {product.data.kind === "premium" && (
                                <label className="checkbox-label">
                                    <input
                                        type="checkbox"
                                        disabled={
                                            !canStock && !product.data.use_stock
                                        }
                                        checked={product.data.use_stock}
                                        onChange={(event) =>
                                            product.setData(
                                                "use_stock",
                                                event.target.checked,
                                            )
                                        }
                                    />
                                    Gunakan stok premium toko{" "}
                                    {canStock
                                        ? ""
                                        : "(memerlukan akses stok premium sendiri)"}
                                </label>
                            )}
                            <CatalogPicker
                                perPage={5}
                                enabled={productOpen}
                                url={base + "/products/mappings"}
                                kind={product.data.kind}
                                searchLabel={product.data.kind === 'postpaid' ? 'Pilih produk bayar' : undefined}
                                selected={mappings}
                                onToggle={(option) =>
                                    toggleMapping(option as MappingOption)
                                }
                            />
                            {product.data.kind === 'postpaid' && <PostpaidMappings base={base} items={mappings} values={product.data.postpaid_pairs} onChange={values=>product.setData('postpaid_pairs',values)}/>}
                        </section>
                    )}
                </form>
            </Modal>
            <Modal
                open={ruleOpen}
                wide
                title={
                    editingRule ? "Edit aturan harga" : "Tambah aturan harga"
                }
                onClose={() => setRuleOpen(false)}
                busy={rule.processing}
                footer={
                    <>
                        <button
                            className="button secondary"
                            type="button"
                            disabled={rule.processing}
                            onClick={() => setRuleOpen(false)}
                        >
                            Batal
                        </button>
                        <button
                            className="button"
                            type="submit"
                            form="catalog-pricing-form"
                            disabled={rule.processing}
                        >
                            Simpan aturan
                        </button>
                    </>
                }
            >
                <form
                    id="catalog-pricing-form"
                    className="form-stack"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (
                            ruleMarkupMode === "own" &&
                            !rule.data.markup.trim()
                        ) {
                            rule.setError(
                                "markup",
                                "Isi nilai markup sendiri, atau pilih markup bawaan.",
                            );
                            return;
                        }
                        rule.transform((data) => ({
                            ...data,
                            markup:
                                ruleMarkupMode === "own"
                                    ? data.markup.trim()
                                    : null,
                            auto_update: autoValue(data.auto_update),
                            pricing_basis: data.pricing_basis === "inherit" ? null : data.pricing_basis,
                        }));
                        const options = {
                            preserveScroll: true,
                            onSuccess: () => setRuleOpen(false),
                        };
                        if (editingRule)
                            rule.patch(
                                base + "/pricing/" + editingRule.id,
                                options,
                            );
                        else rule.post(base + "/pricing", options);
                    }}
                >
                    <FormErrors errors={rule.errors} />
                    <label>
                        Tingkat aturan
                        <select
                            value={rule.data.scope}
                            onChange={(event) =>
                                rule.setData({
                                    ...rule.data,
                                    scope: event.target.value,
                                    key: "",
                                })
                            }
                        >
                            <option value="category">
                                Kategori utama (contoh: Mobile Legends)
                            </option>
                            <option value="service">
                                Layanan (contoh: Game)
                            </option>
                        </select>
                    </label>
                    <PricingTargetInput
                        key={
                            (editingRule?.id ?? "new") +
                            ":" +
                            rule.data.scope +
                            ":" +
                            ruleOpen
                        }
                        storeId={store.id}
                        scope={rule.data.scope}
                        value={rule.data.key}
                        onChange={(value) => rule.setData("key", value)}
                    />
                    <p className="muted">
                        {rule.data.scope === "service"
                            ? "Berlaku untuk seluruh kategori dan produk dalam layanan yang dipilih, kecuali yang memiliki markup sendiri."
                            : "Pilih nama kategori utama, misalnya Mobile Legends. Untuk seluruh kategori dalam Game, pilih tingkat Layanan."}
                    </p>
                    <label>Dasar modal untuk markup
                        <select value={rule.data.pricing_basis} onChange={event=>rule.setData("pricing_basis",event.target.value as "inherit"|"cheapest"|"highest")}>
                            <option value="inherit">{rule.data.scope==="category"?"Ikuti layanan (bawaan: termahal)":"Bawaan: parsing termahal"}</option>
                            <option value="cheapest">Parsing dengan modal termurah</option>
                            <option value="highest">Parsing dengan modal termahal</option>
                        </select>
                    </label>
                    <p className="field-help">Dasar modal berlaku untuk semua produk, termasuk yang memiliki markup sendiri. Prioritas: kategori → layanan. Transaksi tetap dari sumber termurah.</p>
                    <PricingSettings
                        scope={
                            rule.data.scope === "service"
                                ? "service"
                                : "category"
                        }
                        mode={ruleMarkupMode}
                        markup={rule.data.markup}
                        autoUpdate={rule.data.auto_update}
                        onMode={(value) => {
                            setRuleMarkupMode(value);
                            rule.clearErrors("markup");
                        }}
                        onMarkup={(value) => rule.setData("markup", value)}
                        onAutoUpdate={(value) =>
                            rule.setData("auto_update", value)
                        }
                    />
                </form>
            </Modal>
            <ConfirmDialog
                open={!!bulkAction}
                title={
                    (bulkAction?.action === "delete"
                        ? "Hapus"
                        : bulkAction?.action === "deactivate"
                          ? "Nonaktifkan"
                          : "Aktifkan") +
                    " " +
                    (bulkAction?.name
                        ? "produk ini"
                        : (bulkAction?.ids.length ?? 0) + " produk") +
                    "?"
                }
                confirmLabel={
                    bulkAction?.action === "delete"
                        ? "Hapus produk"
                        : bulkAction?.action === "deactivate"
                          ? "Nonaktifkan"
                          : "Aktifkan"
                }
                danger={bulkAction?.action === "delete"}
                busy={bulk.processing}
                onCancel={() => setBulkAction(null)}
                onConfirm={() => {
                    if (!bulkAction) return;
                    bulk.transform(() => ({
                        action: bulkAction.action,
                        ids: bulkAction.ids,
                    }));
                    bulk.post(base + "/products/bulk", {
                        preserveScroll: true,
                        onSuccess: () => {
                            setSelected([]);
                            setBulkAction(null);
                        },
                    });
                }}
            >
                <FormErrors errors={bulk.errors} />
                {bulkAction?.name && (
                    <p>
                        <strong>{bulkAction.name}</strong>
                    </p>
                )}
                <p>
                    {bulkAction?.action === "delete"
                        ? "Produk beserta parsing dan stok ready akan dihapus. Jika ada transaksi berjalan atau stok dipesan/terkirim, seluruh penghapusan dibatalkan. Gunakan Nonaktifkan untuk menghentikan pesanan baru."
                        : bulkAction?.action === "deactivate"
                          ? "Produk tidak lagi bisa dipesan dari web atau bot. Data produk dan riwayat transaksi tetap disimpan."
                          : "Produk diaktifkan kembali. Ketersediaan tetap mengikuti stok, pemasok, dan harga jual."}
                </p>
            </ConfirmDialog>
            <ConfirmDialog
                open={!!removing}
                title={
                    "Hapus " +
                    (removing?.type === "product" ? "produk" : "aturan") +
                    "?"
                }
                confirmLabel="Hapus"
                danger
                busy={deletion.processing}
                onCancel={() => setRemoving(null)}
                onConfirm={() => {
                    if (removing)
                        deletion.delete(
                            base +
                                "/" +
                                (removing.type === "product"
                                    ? "products"
                                    : "pricing") +
                                "/" +
                                removing.id,
                            {
                                preserveScroll: true,
                                onSuccess: () => setRemoving(null),
                            },
                        );
                }}
            >
                <FormErrors errors={deletion.errors} />
                <p>{removing?.name}</p>
                <p>
                    {removing?.type === "product"
                        ? "Produk beserta parsing dan stok ready terkait akan dihapus. Produk yang masih digunakan transaksi berjalan atau memiliki stok dipesan/terkirim harus dinonaktifkan."
                        : "Setelah aturan dihapus, markup dan pembaruan otomatis mengikuti aturan lain yang tersedia. Jika tidak ada, markup 0 dan pembaruan otomatis nonaktif."}
                </p>
            </ConfirmDialog>
        </WorkspaceLayout>
    );
}
