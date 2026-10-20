-- Runs once, when the mysql-data volume is first created (backlog P0.2-BE-03).
-- The image already created MYSQL_DATABASE (ledgerflow) and MYSQL_USER with access to it.
CREATE DATABASE IF NOT EXISTS ledgerflow      CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS ledgerflow_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
CREATE DATABASE IF NOT EXISTS ledgerflow_e2e  CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
GRANT ALL PRIVILEGES ON `ledgerflow`.*      TO 'ledgerflow'@'%';
GRANT ALL PRIVILEGES ON `ledgerflow_test`.* TO 'ledgerflow'@'%';
GRANT ALL PRIVILEGES ON `ledgerflow_e2e`.*  TO 'ledgerflow'@'%';
FLUSH PRIVILEGES;
