# Pruebas de integración

Para una nueva prueba MariaDB aislada, añade `// @brvtal-ci database` en la cabecera (primeros 4096 bytes), exige el entorno descartable y emite `BRVTAL_MARKED_DATABASE_PASS: <nombre.php>` solo después de las aserciones reales; no la registres en el workflow, `package.json` ni otro fixture ejecutado por la suite.
