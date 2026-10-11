-- Extend social_queue to support all content types beyond timeline/blog
ALTER TABLE social_queue MODIFY COLUMN content_type VARCHAR(50) NOT NULL;
