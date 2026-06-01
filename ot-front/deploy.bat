git pull
docker build . -t ot-front:latest && docker stop ot-front & docker rm ot-front & docker run -d --restart unless-stopped --name ot-front -p 9050:80 ot-front:latest
