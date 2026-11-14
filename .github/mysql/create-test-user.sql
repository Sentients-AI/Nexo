-- Test-only MySQL account (ADR-0009 §3a, M6-D-03). Run as root against a
-- disposable server: the CI service container, or the local Docker check in
-- docs/engineering/testing.md. Never run it against a shared or deployed server.
--
-- The tests connect as nexo_test, whose only privileges are on databases
-- matching `nexo\_testing%`. The backslash makes `_` literal, so the pattern
-- does not also match names such as nexoXtesting.

CREATE USER 'nexo_test'@'%' IDENTIFIED BY 'nexo_test';

GRANT ALL PRIVILEGES ON `nexo\_testing%`.* TO 'nexo_test'@'%';
SHOW WARNINGS;

-- A database outside the pattern, owned by root, for the negative
-- privilege tests (tests/Platform/TestUserPrivilegesTest.php).
CREATE DATABASE nexo_ci_canary;
CREATE TABLE nexo_ci_canary.sentinel (id INT PRIMARY KEY);
INSERT INTO nexo_ci_canary.sentinel VALUES (1);
