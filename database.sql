CREATE TABLE IF NOT EXISTS public.urls (
    id integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name varchar(255) NOT NULL UNIQUE,
    created_at timestamptz NOT NULL
);

