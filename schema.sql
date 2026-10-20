CREATE DATABASE IF NOT EXISTS library_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE library_db;

CREATE TABLE users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(150) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('reader','admin') NOT NULL DEFAULT 'reader',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE books (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(255) NOT NULL,
 author VARCHAR(180) NOT NULL,
 genre VARCHAR(100) NOT NULL DEFAULT '',
 isbn VARCHAR(20) NULL UNIQUE,
 description TEXT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_books_title(title),
 INDEX idx_books_author(author),
 INDEX idx_books_genre(genre)
) ENGINE=InnoDB;

CREATE TABLE requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 book_id BIGINT UNSIGNED NOT NULL,
 status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_requests_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_requests_book FOREIGN KEY(book_id) REFERENCES books(id) ON DELETE CASCADE,
 INDEX idx_requests_status(status)
) ENGINE=InnoDB;

CREATE TABLE loans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 book_id BIGINT UNSIGNED NOT NULL,
 request_id BIGINT UNSIGNED NULL,
 issued_at DATE NOT NULL,
 due_at DATE NOT NULL,
 returned_at DATE NULL,
 status ENUM('issued','returned','overdue') NOT NULL DEFAULT 'issued',
 CONSTRAINT fk_loans_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_loans_book FOREIGN KEY(book_id) REFERENCES books(id) ON DELETE RESTRICT,
 CONSTRAINT fk_loans_request FOREIGN KEY(request_id) REFERENCES requests(id) ON DELETE SET NULL,
 INDEX idx_loans_user_status(user_id,status),
 INDEX idx_loans_due(due_at)
) ENGINE=InnoDB;

INSERT INTO books(title,author,genre,isbn,description) VALUES
('1984','Джордж Оруэлл','Антиутопия','9785170988254','Роман-антиутопия о тотальном контроле.'),
('Мастер и Маргарита','Михаил Булгаков','Классика','9785699981345','Роман о добре, зле и свободе.'),
('Гарри Поттер и философский камень','Дж. К. Роулинг','Фэнтези','9785389077848','Первая книга о юном волшебнике.'),
('Война и мир','Лев Толстой','Классика','9785170901234','Роман-эпопея.'),
('Три товарища','Эрих Мария Ремарк','Проза','9785699993219','Роман о дружбе и любви.'),
('Алхимик','Пауло Коэльо','Философия','9785699871234','Притча о поиске мечты.'),
('Убить пересмешника','Харпер Ли','Классика','9785171123456','Роман о справедливости.'),
('Дюна','Фрэнк Герберт','Фантастика','9785171234567','Эпическая научная фантастика.');
