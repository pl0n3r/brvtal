-- BRVTAL Blog 01
-- Additive/idempotent migration for editorial posts and taxonomy.

CREATE TABLE IF NOT EXISTS blog_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(220) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  excerpt VARCHAR(700) NULL,
  body LONGTEXT NULL,
  cover_image VARCHAR(500) NULL,
  seo_title VARCHAR(190) NULL,
  seo_description VARCHAR(320) NULL,
  status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  featured TINYINT(1) NOT NULL DEFAULT 0,
  published_at DATETIME NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_blog_posts_slug (slug),
  INDEX idx_blog_posts_public (status,published_at,sort_order),
  INDEX idx_blog_posts_featured (featured,status,published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_blog_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_post_tags (
  post_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id,tag_id),
  INDEX idx_blog_post_tags_tag (tag_id,post_id),
  CONSTRAINT fk_blog_post_tags_post FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_blog_post_tags_tag FOREIGN KEY (tag_id) REFERENCES blog_tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS blog_post_relations (
  post_id INT UNSIGNED NOT NULL,
  related_type ENUM('event','artist','set','release') NOT NULL,
  related_id INT UNSIGNED NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  PRIMARY KEY (post_id,related_type,related_id),
  INDEX idx_blog_post_relations_target (related_type,related_id,sort_order),
  CONSTRAINT fk_blog_post_relations_post FOREIGN KEY (post_id) REFERENCES blog_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
