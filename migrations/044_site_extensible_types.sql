-- Admin roles and document types become plain text, so a site can add its own
-- (includes/site-extensions.php: 'roles', 'document_types') without a release
-- ever cutting them off. Existing values are kept as they are.
ALTER TABLE admin_users MODIFY role VARCHAR(40) NOT NULL DEFAULT 'author';
ALTER TABLE documents MODIFY type VARCHAR(40) NOT NULL;
