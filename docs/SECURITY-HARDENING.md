# BRVTAL — Security Hardening Requirements

## Hosting-edge WAF

BRVTAL should use a lightweight web application firewall at the Apache/LiteSpeed layer when the Hostinger environment supports it.

Preferred approach:

- conservative basic ModSecurity rules where Hostinger/LiteSpeed permits them;
- defense-in-depth against common malicious request patterns without introducing a heavy application plugin;
- validate false positives before production activation against `/discadmin`, protected and public APIs, uploads, forms and authentication flows;
- roll out conservatively and keep the configuration compatible with Hostinger shared hosting / LiteSpeed.

The WAF is an additional perimeter control only. It must not replace application-layer input validation, prepared statements, CSRF protection, authentication controls, rate limiting, session hardening, upload validation or public-data allowlists.

No WAF rule is enabled merely by documenting this requirement. Production activation requires explicit compatibility validation in the real Hostinger environment.
