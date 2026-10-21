-- Runs once, when the mysql-data volume is first created (backlog P0.2-BE-03).
-- The image already created MYSQL_DATABASE (pluto) and MYSQL_USER with access to it.
CREATE DATABASE IF NOT EXISTS pluto      CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS pluto_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS pluto_e2e  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON `pluto`.*      TO 'pluto'@'%';
GRANT ALL PRIVILEGES ON `pluto_test`.* TO 'pluto'@'%';
GRANT ALL PRIVILEGES ON `pluto_e2e`.*  TO 'pluto'@'%';
FLUSH PRIVILEGES;
