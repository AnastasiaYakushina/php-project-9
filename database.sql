CREATE TABLE IF NOT EXISTS public.urls (
	id serial4 NOT NULL,
	"name" varchar(255) NOT NULL UNIQUE,
	created_at timestamp DEFAULT CURRENT_TIMESTAMP NULL,
	CONSTRAINT urls_pkey PRIMARY KEY (id)
);
