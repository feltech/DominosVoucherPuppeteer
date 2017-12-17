CREATE TABLE IF NOT EXISTS branches (id INT PRIMARY KEY, last_updated LONG NOT NULL);
CREATE TABLE IF NOT EXISTS postcodes(
	postcode VARCHAR(10) PRIMARY KEY, 
	branch_id INT,
	last_updated LONG,
	FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS vouchers_last_updated(last_updated LONG);
CREATE TABLE IF NOT EXISTS vouchers (code VARCHAR(10) PRIMARY KEY, description TEXT);
CREATE TABLE IF NOT EXISTS working (
	code VARCHAR(10),
	branch_id INT,
	FOREIGN KEY (code) REFERENCES vouchers(code) ON DELETE CASCADE,
	FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);
	
DELETE FROM vouchers_last_updated;
INSERT INTO vouchers_last_updated VALUES(0);