import { Head, InfiniteScroll, router } from '@inertiajs/react';
import type { InfiniteScrollActionSlotProps } from '@inertiajs/core';
import {
    ArrowUp,
    Barcode,
    ChevronDown,
    CircleAlert,
    CircleCheck,
    ExternalLink,
    ImageOff,
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
import { Card, CardContent } from '@/components/ui/card';
import { Empty, EmptyContent, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty';
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group';
import { Kbd } from '@/components/ui/kbd';
import { Separator } from '@/components/ui/separator';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { IngestionSummary, ProductCard, ProductPage } from '@/types';

interface Props {
    q: string;
    products: ProductPage;
    lastRun: IngestionSummary | null;
    total: number;
}

const SUGGESTIONS = ['chocolate', 'biscuits', 'coca-cola', 'ferrero', 'nutela'];

// After this many automatic page loads, switch to a "Load more" button so
// the footer stays reachable.
const AUTO_PAGES = 5;

const GRID = 'grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4';

export default function Search({ q, products, lastRun, total }: Props) {
    const [term, setTerm] = useState(q);
    const [searching, setSearching] = useState(false);
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
            // A new query starts a fresh list instead of appending to the old one.
            reset: ['products'],
            onStart: () => setSearching(true),
            onFinish: () => setSearching(false),
        });
    }

    return (
        <>
            <Head title={q || undefined} />

            <div className="flex min-h-screen flex-col">
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

                <main className="mx-auto w-full max-w-6xl flex-1 px-4 pt-10 pb-16">
                    <section className="mx-auto max-w-2xl text-center">
                        <h1 className="text-3xl font-semibold tracking-tight text-balance sm:text-4xl">Find any packaged food</h1>
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
                            <InputGroup className="bg-card h-11 flex-1 shadow-sm">
                                <InputGroupAddon>
                                    <SearchIcon />
                                </InputGroupAddon>
                                <InputGroupInput
                                    ref={inputRef}
                                    type="search"
                                    name="q"
                                    value={term}
                                    onChange={(e) => setTerm(e.target.value)}
                                    placeholder="Try “dark chocolate”, “ferrero” or a barcode"
                                    aria-label="Search products"
                                    autoFocus
                                    className="text-base [&::-webkit-search-cancel-button]:hidden"
                                />
                                <InputGroupAddon align="inline-end">
                                    {searching ? (
                                        <Spinner aria-label="Searching" />
                                    ) : term ? (
                                        <InputGroupButton
                                            size="icon-xs"
                                            aria-label="Clear search"
                                            onClick={() => {
                                                setTerm('');
                                                inputRef.current?.focus();
                                            }}
                                        >
                                            <X />
                                        </InputGroupButton>
                                    ) : (
                                        <Kbd className="hidden sm:inline-flex">/</Kbd>
                                    )}
                                </InputGroupAddon>
                            </InputGroup>
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
                        <InfiniteScroll
                            data="products"
                            buffer={600}
                            manualAfter={AUTO_PAGES}
                            aria-busy={searching}
                            className={cn(GRID, 'transition-opacity', searching && 'pointer-events-none opacity-50')}
                            previous={(slot) => <LoadMore {...slot} label="Load earlier results" icon={ArrowUp} />}
                            next={(slot) => (
                                <>
                                    <LoadMore {...slot} label="Load more" icon={ChevronDown} />
                                    {!slot.hasMore && <EndOfResults />}
                                </>
                            )}
                        >
                            {products.data.map((product) => (
                                <ProductTile key={product.id} product={product} />
                            ))}
                        </InfiniteScroll>
                    )}
                </main>

                <footer className="text-muted-foreground border-t py-6 text-center text-xs">
                    Data from{' '}
                    <a className="hover:text-foreground underline underline-offset-4" href="https://world.openfoodfacts.org" target="_blank" rel="noopener">
                        Open Food Facts
                    </a>{' '}
                    (ODbL). Search by PostgreSQL full-text + pg_trgm via Laravel Scout.
                </footer>

                <BackToTop />
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
                Showing {products.data.length.toLocaleString()} of {products.total.toLocaleString()}
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

function TileSkeleton() {
    return (
        <Card className="gap-0 overflow-hidden py-0">
            <Skeleton className="aspect-square rounded-none" />
            <div className="flex flex-col gap-2 p-3 sm:p-4">
                <Skeleton className="h-4 w-4/5" />
                <Skeleton className="h-3.5 w-1/2" />
                <div className="flex gap-1 pt-1">
                    <Skeleton className="h-5 w-14 rounded-full" />
                    <Skeleton className="h-5 w-10 rounded-full" />
                </div>
                <Skeleton className="mt-2 h-3 w-24" />
            </div>
        </Card>
    );
}

/** Previous/next slot: skeleton row while fetching, a button once auto-loading stops. */
function LoadMore({ loading, hasMore, manualMode, fetch, label, icon: Icon }: InfiniteScrollActionSlotProps & { label: string; icon: typeof ArrowUp }) {
    if (loading) {
        return (
            <div className={cn(GRID, 'py-4')} aria-hidden>
                {Array.from({ length: 4 }, (_, i) => (
                    <TileSkeleton key={i} />
                ))}
            </div>
        );
    }

    if (!hasMore || !manualMode) return null;

    return (
        <div className="flex justify-center py-8">
            <Button variant="outline" onClick={fetch}>
                <Icon />
                {label}
            </Button>
        </div>
    );
}

function EndOfResults() {
    return (
        <div className="text-muted-foreground flex items-center gap-4 pt-10 text-xs">
            <Separator className="flex-1" />
            You&apos;ve reached the end
            <Separator className="flex-1" />
        </div>
    );
}

function BackToTop() {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        const onScroll = () => setVisible(window.scrollY > 1200);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <Button
            variant="outline"
            size="icon"
            aria-label="Back to top"
            onClick={() => window.scrollTo({ top: 0, behavior: 'smooth' })}
            className={cn(
                'bg-background/90 fixed right-4 bottom-4 z-20 rounded-full shadow-md backdrop-blur transition-all sm:right-6 sm:bottom-6',
                visible ? 'translate-y-0 opacity-100' : 'pointer-events-none translate-y-2 opacity-0',
            )}
        >
            <ArrowUp />
        </Button>
    );
}

function EmptyState({ q, onSuggest }: { q: string; onSuggest: (s: string) => void }) {
    return (
        <Empty className="border">
            <EmptyHeader>
                <EmptyMedia variant="icon">{q ? <SearchX /> : <Package />}</EmptyMedia>
                <EmptyTitle>{q ? `No products match “${q}”` : 'The index is empty'}</EmptyTitle>
                <EmptyDescription>
                    {q ? (
                        'Try fewer words, a brand name, or check the spelling.'
                    ) : (
                        <>
                            Run <code className="bg-muted rounded px-1 py-0.5 font-mono text-xs">php artisan foodfacts:ingest</code> with a
                            queue worker.
                        </>
                    )}
                </EmptyDescription>
            </EmptyHeader>
            {q && (
                <EmptyContent className="flex-row justify-center">
                    {SUGGESTIONS.slice(0, 3).map((s) => (
                        <Button key={s} variant="outline" size="sm" onClick={() => onSuggest(s)}>
                            {s}
                        </Button>
                    ))}
                </EmptyContent>
            )}
        </Empty>
    );
}
