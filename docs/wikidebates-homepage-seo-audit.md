# Six-edition homepage SEO observation

The existing bounded public audit can now observe all six homepages explicitly:

```sh
python3 tests/ci/publicSeoAudit.py --all-homepages --namespace-inventory --expect-sitemap-policy
```

Without `--all-homepages`, the FR/EN-only scope is unchanged. Each selected edition adds at most one homepage GET and one same-origin static OG image HEAD, each with at most one permitted redirect. With six editions and namespace inventory this is at most 48 HTTP requests including redirects. No recursive crawl or runtime configuration change. The audit reports raw head metadata, hreflang links, selected JSON-LD identities and images for manual comparison; it does not assert semantic correctness or indexability. External image URLs are reported but not automatically fetched. Verify their origin and accessibility separately. A missing or failed HEAD is not proof of an inaccessible image.

Run before and after the grouped rollout; only evaluate the new SEO output after the appropriate caches are refreshed. `--expect-sitemap-policy` remains a sitemap-index check only. Native debate/argument checks remain separate.
