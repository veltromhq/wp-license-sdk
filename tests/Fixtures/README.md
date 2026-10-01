# Fixtures

`server-token.json` is a token and JWKS issued by the veltrom.com application itself
(`App\Licensing\Token\TokenIssuer` and `SigningKeyRepository::jwks()`), for an activation of a
seeded WordPress product with installation id `sha256("shop.example.com")`, created in a
rolled-back transaction against a local development database. `ServerContractTest` verifies it,
so a change to the server's token format fails here first.

To refresh it, run the same steps against a current checkout of veltrom.com: activate a
WordPress-product licence for that installation id, issue a token, and write
`{issuer, audience, installation_id, now, token, jwks}` to this file.
