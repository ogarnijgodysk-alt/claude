"use client";

import { useContext, type AnchorHTMLAttributes, type ReactNode } from "react";
import { RouterContext } from "./navigation";

// Stand-in for next/link: internal links navigate the in-memory router.
export default function Link({ href, children, onClick, ...rest }: AnchorHTMLAttributes<HTMLAnchorElement> & { href: string; children?: ReactNode }) {
  const router = useContext(RouterContext);
  return (
    <a
      href={href}
      {...rest}
      onClick={(e) => {
        onClick?.(e);
        if (e.defaultPrevented || /^https?:/.test(href) || e.metaKey || e.ctrlKey) return;
        e.preventDefault();
        router.navigate(href);
      }}
    >
      {children}
    </a>
  );
}
