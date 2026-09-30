export interface ProductCard {
    id: number;
    code: string;
    name: string | null;
    brands: string | null;
    categories: string[];
    more_categories: number;
    image_url: string | null;
    url: string;
}

export interface ProductPage {
    data: ProductCard[];
    current_page: number;
    last_page: number;
    total: number;
}

export interface IngestionSummary {
    status: 'pending' | 'running' | 'completed' | 'failed';
    when: string;
    pages_fetched: number;
    products_seen: number;
    stop_reason: string | null;
}
