# Test fixtures

`snapbi_test_private.pem` / `snapbi_test_public.pem` are a throwaway RSA-2048 pair
generated solely so the Snap-BI webhook verifier can be tested against a real
SHA256withRSA signature. They are **not** credentials: they guard no environment
and are safe to publish. Never reuse them for anything.

Regenerate with:

```bash
openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:2048 -out snapbi_test_private.pem
openssl rsa -in snapbi_test_private.pem -pubout -out snapbi_test_public.pem
```
