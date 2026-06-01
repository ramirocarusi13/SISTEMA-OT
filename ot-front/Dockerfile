FROM node:18-alpine AS base
ENV PNPM_HOME="/pnpm"
ENV PATH="$PNPM_HOME:$PATH"
#RUN corepack enable
RUN npm install -g pnpm@latest
RUN pnpm config set verify-store-integrity false

COPY . /front
WORKDIR /front

FROM base AS prod-deps
RUN --mount=type=cache,id=pnpm,target=/pnpm/store pnpm install --prod --no-frozen-lockfile

FROM base AS build
RUN --mount=type=cache,id=pnpm,target=/pnpm/store pnpm install --no-frozen-lockfile
RUN pnpm run build --mode production

FROM nginx:alpine
COPY ./nginx/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /front/dist /var/www/html/

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]