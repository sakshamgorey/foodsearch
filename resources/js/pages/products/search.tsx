import { Head, router } from '@inertiajs/react';
import {
    Barcode,
    CircleAlert,
    CircleCheck,
    ExternalLink,
    ImageOff,
    Loader2,
    Package,
    RefreshCw,
    Search as SearchIcon,
    SearchX,
    Sparkles,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Pagination,
    PaginationContent,
    PaginationEllipsis,
    PaginationItem,
    PaginationLink,
    PaginationNext,
    PaginationPrevious,
} from '@/components/ui/pagination';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import type { IngestionSummary, ProductCard, ProductPage } from '@/types';

interface Props {
    q: string;
    products: ProductPage;
    lastRun: IngestionSummary | null;
    total: number;
}

const SUGGESTIONS = ['chocolate', 'biscuits', 'coca-cola', 'ferrero', 'nutela'];

export default function Search({ q, products, lastRun, total }: Props) {
    const [term, setTerm] = useState(q);
    const [loading, setLoading] = useState(false);
    const inputRef = useRef<HTMLInputElement>(null);
    const firstRender = useRef(true);

    // Live search: debounce keystrokes into partial Inertia visits.
    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }
        if (term.trim() === q) return;

        const id = setTimeout(() => visit(term), 300);
        return () => clearTimeout(id);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [term]);

    useEffect(() => {
        const start = router.on('start', () => setLoading(true));
        const finish = router.on('finish', () => setLoading(false));
        return () => {
            start();
            finish();
        };
    }, []);

    // "/" focuses search, Escape clears it.
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const typing = (e.target as HTMLElement)?.tagName === 'INPUT';
            if (e.key === '/' && !typing) {
                e.preventDefault();
                inputRef.current?.focus();
            }
            if (e.key === 'Escape' && document.activeElement === inputRef.current) {
                setTerm('');
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, []);

    function visit(value: string) {
        const next = value.trim();
        router.get('/', next ? { q: next } : {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['q', 'products'],
        });
    }

    return (
        <>
            <Head title={q || undefined} />

            <div className="min-h-screen">
                <header className="bg-background/80 sticky top-0 z-10 border-b backdrop-blur">
                    <div className="mx-auto flex h-14 max-w-6xl items-center justify-between gap-4 px-4">
                        <a href="/" className="flex items-center gap-2 font-semibold tracking-tight">
                            <span className="bg-primary text-primary-foreground grid size-7 place-items-center rounded-md">
                                <Package className="size-4" />
                            </span>
                            Food Search
                        </a>
                        <IngestStatus run={lastRun} total={total} />
                    </div>
                </header>

                <main className="mx-auto max-w-6xl px-4 pt-10 pb-16">
                    <section className="mx-auto max-w-2xl text-center">
                        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">Find any packaged food</h1>
                        <p className="text-muted-foreground mt-2 text-sm sm:text-base">
                            Search {total.toLocaleString()} Open Food Facts products by name, brand, category or barcode.
                        </p>

                        <form
                            className="mt-6 flex gap-2"
                            role="search"
                            onSubmit={(e) => {
                                e.preventDefault();
                                visit(term);
                            }}
                        >
                            <div className="relative flex-1">
                                <SearchIcon className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                <Input
                                    ref={inputRef}
                                    type="search"
                                    name="q"
                                    value={term}
                                    onChange={(e) => setTerm(e.target.value)}
                                    placeholder="Try “dark chocolate”, “ferrero” or a barcode"
                                    aria-label="Search products"
                                    autoFocus
                                    className="bg-card h-11 pr-16 pl-9 text-base shadow-sm [&::-webkit-search-cancel-button]:hidden"
                                />
                                <div className="absolute top-1/2 right-2 flex -translate-y-1/2 items-center gap-1">
                                    {loading ? (
                                        <Loader2 className="text-muted-foreground size-4 animate-spin" aria-label="Searching" />
                                    ) : term ? (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-7"
                                            aria-label="Clear search"
                                            onClick={() => {
                                                setTerm('');
                                                inputRef.current?.focus();
                                            }}
                                        >
                                            <X />
                                        </Button>
                                    ) : (
                                        <kbd className="text-muted-foreground bg-muted hidden rounded border px-1.5 font-mono text-[11px] sm:inline-block">
                                            /
                                        </kbd>
                                    )}
                                </div>
                            </div>
                            <Button type="submit" size="lg" className="h-11">
                                Search
                            </Button>
                        </form>

                        <div className="mt-4 flex flex-wrap items-center justify-center gap-2">
                            <span className="text-muted-foreground text-xs">Try</span>
                            {SUGGESTIONS.map((s) => (
                                <Button
                                    key={s}
                                    type="button"
                                    variant={q === s ? 'default' : 'outline'}
                                    size="sm"
                                    className="h-7 rounded-full px-3 text-xs"
                                    onClick={() => setTerm(s)}
                                >
                                    {s === 'nutela' && <Sparkles className="size-3" />}
                                    {s}
                                </Button>
                            ))}
                        </div>
                    </section>

                    <Separator className="my-8" />

                    <ResultsHeader q={q} products={products} />

                    {products.data.length === 0 ? (
                        <EmptyState q={q} onSuggest={setTerm} />
                    ) : (
                        <div
                            className={cn(
                                'grid grid-cols-2 gap-4 transition-opacity sm:grid-cols-3 lg:grid-cols-4',
                                loading && 'pointer-events-none opacity-50',
                            )}
                            aria-busy={loading}
                        >
                            {products.data.map((product) => (
                                <ProductTile key={product.id} product={product} />
                            ))}
                        </div>
                    )}

                    {products.last_page > 1 && <Pager products={products} />}
                </main>

                <footer className="text-muted-foreground border-t py-6 text-center text-xs">
                    Data from{' '}
                    <a className="hover:text-foreground underline underline-offset-4" href="https://world.openfoodfacts.org" target="_blank" rel="noopener">
                        Open Food Facts
                    </a>{' '}
                    (ODbL). Search by PostgreSQL full-text + pg_trgm via Laravel Scout.
                </footer>
            </div>
        </>
    );
}

function IngestStatus({ run, total }: { run: IngestionSummary | null; total: number }) {
    if (!run) {
        return <Badge variant="outline">No ingest yet</Badge>;
    }

    const tone = {
        completed: { icon: CircleCheck, className: 'text-success border-success/30 bg-success/10' },
        running: { icon: RefreshCw, className: 'text-warning border-warning/30 bg-warning/10' },
        pending: { icon: RefreshCw, className: 'text-warning border-warning/30 bg-warning/10' },
        failed: { icon: CircleAlert, className: 'text-destructive border-destructive/30 bg-destructive/10' },
    }[run.status];
    const Icon = tone.icon;

    return (
        <div className="flex items-center gap-3 text-xs">
            <span className="text-muted-foreground hidden sm:inline">{total.toLocaleString()} products</span>
            <Badge
                variant="outline"
                className={cn('gap-1.5', tone.className)}
                title={`${run.pages_fetched} pages · ${run.products_seen} products${run.stop_reason ? ` · ${run.stop_reason}` : ''}`}
            >
                <Icon className={cn(run.status === 'running' && 'animate-spin')} />
                <span className="capitalize">{run.status}</span>
                <span className="text-muted-foreground font-normal">{run.when}</span>
            </Badge>
        </div>
    );
}

function ResultsHeader({ q, products }: { q: string; products: ProductPage }) {
    if (products.total === 0) return null;

    return (
        <div className="mb-4 flex items-baseline justify-between gap-4">
            <h2 className="text-sm font-medium">
                {q ? (
                    <>
                        {products.total.toLocaleString()} {products.total === 1 ? 'result' : 'results'} for{' '}
                        <span className="text-primary">“{q}”</span>
                    </>
                ) : (
                    'Newest ingested'
                )}
            </h2>
            <span className="text-muted-foreground text-xs tabular-nums">
                {products.from}–{products.to} of {products.total.toLocaleString()}
            </span>
        </div>
    );
}

function ProductTile({ product }: { product: ProductCard }) {
    const [broken, setBroken] = useState(false);
    const showImage = product.image_url && !broken;

    return (
        <a
            href={product.url}
            target="_blank"
            rel="noopener"
            className="group focus-visible:ring-ring/50 rounded-xl outline-none focus-visible:ring-[3px]"
        >
            <Card className="h-full gap-0 overflow-hidden py-0 transition-all group-hover:-translate-y-0.5 group-hover:shadow-md">
                <div className={cn('relative aspect-square border-b', showImage ? 'bg-white' : 'bg-muted/40')}>
                    {showImage ? (
                        <img
                            src={product.image_url!}
                            alt=""
                            loading="lazy"
                            referrerPolicy="no-referrer"
                            onError={() => setBroken(true)}
                            className="size-full object-contain p-4 transition-transform group-hover:scale-[1.03]"
                        />
                    ) : (
                        <div className="text-muted-foreground flex size-full flex-col items-center justify-center gap-2">
                            <ImageOff className="size-6 opacity-50" />
                            <span className="text-xs">No image</span>
                        </div>
                    )}
                    <span className="bg-background/90 text-foreground absolute top-2 right-2 rounded-md border p-1 opacity-0 shadow-sm transition-opacity group-hover:opacity-100">
                        <ExternalLink className="size-3.5" />
                    </span>
                </div>

                <CardContent className="flex flex-1 flex-col gap-2 p-3 sm:p-4">
                    <div>
                        <h3 className="line-clamp-2 leading-snug font-medium">{product.name ?? 'Unnamed product'}</h3>
                        {product.brands && <p className="text-muted-foreground mt-0.5 line-clamp-1 text-sm">{product.brands}</p>}
                    </div>

                    {product.categories.length > 0 && (
                        <div className="flex flex-wrap gap-1">
                            {product.categories.map((c) => (
                                <Badge key={c} variant="secondary" className="max-w-full truncate font-normal">
                                    {c}
                                </Badge>
                            ))}
                            {product.more_categories > 0 && (
                                <Badge variant="outline" className="text-muted-foreground font-normal">
                                    +{product.more_categories}
                                </Badge>
                            )}
                        </div>
                    )}

                    <p className="text-muted-foreground mt-auto flex items-center gap-1.5 pt-1 font-mono text-[11px]">
                        <Barcode className="size-3.5" />
                        {product.code}
                    </p>
                </CardContent>
            </Card>
        </a>
    );
}

function EmptyState({ q, onSuggest }: { q: string; onSuggest: (s: string) => void }) {
    return (
        <Card className="border-dashed shadow-none">
            <CardHeader className="items-center text-center">
                <div className="bg-muted mx-auto mb-2 grid size-12 place-items-center rounded-full">
                    {q ? <SearchX className="text-muted-foreground size-5" /> : <Package className="text-muted-foreground size-5" />}
                </div>
                <CardTitle>{q ? `No products match “${q}”` : 'The index is empty'}</CardTitle>
                <CardDescription>
                    {q ? (
                        'Try fewer words, a brand name, or check the spelling.'
                    ) : (
                        <>
                            Run <code className="bg-muted rounded px-1 py-0.5 font-mono text-xs">php artisan foodfacts:ingest</code> with a
                            queue worker.
                        </>
                    )}
                </CardDescription>
            </CardHeader>
            {q && (
                <CardContent className="flex justify-center gap-2">
                    {SUGGESTIONS.slice(0, 3).map((s) => (
                        <Button key={s} variant="outline" size="sm" onClick={() => onSuggest(s)}>
                            {s}
                        </Button>
                    ))}
                </CardContent>
            )}
        </Card>
    );
}

function Pager({ products }: { products: ProductPage }) {
    const first = products.window[0]?.page ?? 1;
    const last = products.window[products.window.length - 1]?.page ?? products.last_page;

    return (
        <Pagination className="mt-10">
            <PaginationContent>
                <PaginationItem>
                    <PaginationPrevious href={products.prev_url} />
                </PaginationItem>

                {first > 1 && (
                    <>
                        <PaginationItem>
                            <PaginationLink href={products.first_url}>1</PaginationLink>
                        </PaginationItem>
                        {first > 2 && (
                            <PaginationItem>
                                <PaginationEllipsis />
                            </PaginationItem>
                        )}
                    </>
                )}

                {products.window.map((link) => (
                    <PaginationItem key={link.page}>
                        <PaginationLink href={link.url} isActive={link.page === products.current_page}>
                            {link.page}
                        </PaginationLink>
                    </PaginationItem>
                ))}

                {last < products.last_page && (
                    <>
                        {last < products.last_page - 1 && (
                            <PaginationItem>
                                <PaginationEllipsis />
                            </PaginationItem>
                        )}
                        <PaginationItem>
                            <PaginationLink href={products.last_url}>{products.last_page}</PaginationLink>
                        </PaginationItem>
                    </>
                )}

                <PaginationItem>
                    <PaginationNext href={products.next_url} />
                </PaginationItem>
            </PaginationContent>
        </Pagination>
    );
}
