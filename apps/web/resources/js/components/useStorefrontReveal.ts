import { useEffect, type RefObject } from "react";

const targets =
    "section, .sf-form-card, .sf-section-title, .ng-category-card, .ng-sale-card, .ng-trending-card, .ng-nominal, .ng-ranking li, .ng-benefits>div, .commerce-account-summary, .table-scroll, .ng-calculator-tabs, .ng-footer-grid>div, .sf-footer-bottom";
const staggered =
    ".ng-category-card, .ng-sale-card, .ng-trending-card, .ng-nominal, .ng-ranking li, .ng-footer-grid>div";

/** Enhance visible content without hiding SSR output or depending on animation support. */
export default function useStorefrontReveal(
    root: RefObject<HTMLDivElement | null>,
    pageKey: string,
) {
    useEffect(() => {
        const container = root.current;
        if (
            !container ||
            !("IntersectionObserver" in window) ||
            !Element.prototype.animate
        )
            return;
        const preference = window.matchMedia(
            "(prefers-reduced-motion: reduce)",
        );
        let seen = new WeakSet<Element>();
        const initiallyVisible = new WeakSet<Element>();
        const animations = new Set<Animation>();
        let frame = 0;
        function reveal(element: Element) {
            if (preference.matches) return;
            const siblings = element.matches(staggered)
                ? Array.from(element.parentElement?.children ?? [])
                : [];
            const delay = Math.min(
                Math.max(0, siblings.indexOf(element)) * 60,
                240,
            );
            const stationary = getComputedStyle(element).position === "sticky";
            const ancestor = element.parentElement?.closest(targets);
            const nested = !!ancestor && container!.contains(ancestor);
            const opacity = initiallyVisible.has(element) || nested ? 1 : 0.25;
            const animation = element.animate(
                [
                    {
                        opacity,
                        transform: stationary
                            ? "none"
                            : `translate3d(0,${nested ? 12 : 22}px,0)`,
                    },
                    { opacity: 1, transform: "none" },
                ],
                {
                    duration: 500,
                    delay,
                    easing: "cubic-bezier(.25,.46,.45,.94)",
                    fill: "backwards",
                },
            );
            animations.add(animation);
            animation.onfinish = () => animations.delete(animation);
            animation.oncancel = () => animations.delete(animation);
        }
        const observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        observer.unobserve(entry.target);
                        reveal(entry.target);
                    }
                }
            },
            { threshold: 0.01, rootMargin: "0px 0px -24px 0px" },
        );
        function scan() {
            if (preference.matches) return;
            container!.querySelectorAll(targets).forEach((element) => {
                if (seen.has(element) || element.closest("header, dialog"))
                    return;
                const bounds = element.getBoundingClientRect();
                if (bounds.top < window.innerHeight && bounds.bottom > 0)
                    initiallyVisible.add(element);
                seen.add(element);
                observer.observe(element);
            });
        }
        function changedPreference() {
            observer.disconnect();
            for (const animation of animations) animation.cancel();
            if (!preference.matches) {
                seen = new WeakSet();
                scan();
            }
        }
        scan();
        const mutations = new MutationObserver(() => {
            cancelAnimationFrame(frame);
            frame = requestAnimationFrame(scan);
        });
        mutations.observe(container, { childList: true, subtree: true });
        preference.addEventListener("change", changedPreference);
        return () => {
            observer.disconnect();
            mutations.disconnect();
            cancelAnimationFrame(frame);
            preference.removeEventListener("change", changedPreference);
            for (const animation of animations) animation.cancel();
        };
    }, [root, pageKey]);
}
