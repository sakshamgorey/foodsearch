import * as React from 'react';
import { Link } from '@inertiajs/react';
import { ChevronLeftIcon, ChevronRightIcon, MoreHorizontalIcon } from 'lucide-react';

import { buttonVariants, type Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

// shadcn/ui pagination, with PaginationLink rendering an Inertia <Link> so
// page changes are XHR visits that keep scroll + component state.

function Pagination({ className, ...props }: React.ComponentProps<'nav'>) {
    return (
        <nav
            role="navigation"
            aria-label="pagination"
            data-slot="pagination"
            className={cn('mx-auto flex w-full justify-center', className)}
            {...props}
        />
    );
}

function PaginationContent({ className, ...props }: React.ComponentProps<'ul'>) {
    return <ul data-slot="pagination-content" className={cn('flex flex-row items-center gap-1', className)} {...props} />;
}

function PaginationItem({ ...props }: React.ComponentProps<'li'>) {
    return <li data-slot="pagination-item" {...props} />;
}

type PaginationLinkProps = {
    isActive?: boolean;
    disabled?: boolean;
    href: string | null;
} & Pick<React.ComponentProps<typeof Button>, 'size'> &
    Omit<React.ComponentProps<'a'>, 'href'>;

function PaginationLink({ className, isActive, disabled, size = 'icon', href, children, ...props }: PaginationLinkProps) {
    const classes = cn(
        buttonVariants({ variant: isActive ? 'outline' : 'ghost', size }),
        (disabled || !href) && 'pointer-events-none opacity-50',
        className,
    );

    if (!href || disabled) {
        return (
            <span aria-disabled="true" data-slot="pagination-link" className={classes}>
                {children}
            </span>
        );
    }

    return (
        <Link
            href={href}
            preserveScroll={false}
            aria-current={isActive ? 'page' : undefined}
            data-slot="pagination-link"
            data-active={isActive}
            className={classes}
            {...(props as Record<string, unknown>)}
        >
            {children}
        </Link>
    );
}

function PaginationPrevious({ className, ...props }: PaginationLinkProps) {
    return (
        <PaginationLink aria-label="Go to previous page" size="default" className={cn('gap-1 px-2.5 sm:pl-2.5', className)} {...props}>
            <ChevronLeftIcon />
            <span className="hidden sm:block">Previous</span>
        </PaginationLink>
    );
}

function PaginationNext({ className, ...props }: PaginationLinkProps) {
    return (
        <PaginationLink aria-label="Go to next page" size="default" className={cn('gap-1 px-2.5 sm:pr-2.5', className)} {...props}>
            <span className="hidden sm:block">Next</span>
            <ChevronRightIcon />
        </PaginationLink>
    );
}

function PaginationEllipsis({ className, ...props }: React.ComponentProps<'span'>) {
    return (
        <span aria-hidden data-slot="pagination-ellipsis" className={cn('flex size-9 items-center justify-center', className)} {...props}>
            <MoreHorizontalIcon className="size-4" />
            <span className="sr-only">More pages</span>
        </span>
    );
}

export {
    Pagination,
    PaginationContent,
    PaginationLink,
    PaginationItem,
    PaginationPrevious,
    PaginationNext,
    PaginationEllipsis,
};
