# Docker & V2RayA Setup on Ubuntu (Iran National Internet)

## 1. Replace APT Mirrors with Arvancloud
```bash
sudo sed -i 's|nova.clouds.archive.ubuntu.com|mirror.arvancloud.ir|g' /etc/apt/sources.list
sudo sed -i 's|archive.ubuntu.com|mirror.arvancloud.ir|g' /etc/apt/sources.list
sudo sed -i 's|security.ubuntu.com|mirror.arvancloud.ir|g' /etc/apt/sources.list

## 2. Rewrite sources.list

bash
sudo tee /etc/apt/sources.list > /dev/null <<EOF
deb https://mirror.arvancloud.ir/ubuntu noble main restricted universe multiverse
deb https://mirror.arvancloud.ir/ubuntu noble-updates main restricted universe multiverse
deb https://mirror.arvancloud.ir/ubuntu noble-backports main restricted universe multiverse
deb https://mirror.arvancloud.ir/ubuntu noble-security main restricted universe multiverse
EOF

## 3. Remove External Repo References

bash
grep -r "noble\|docker.com\|security.ubuntu.com\|archive.ubuntu.com" /etc/apt/
sudo rm /etc/apt/sources.list.d/docker.list
sudo mv /etc/apt/sources.list.d/ubuntu.sources /etc/apt/sources.list.d/ubuntu.sources.disabled

## 4. Update APT & Install Docker

bash
sudo apt update
sudo apt install -y docker.io docker-compose
sudo systemctl enable docker
sudo systemctl start docker

## 5. Configure Docker Internal Mirrors

bash
sudo tee /etc/docker/daemon.json > /dev/null <<EOF
{
  "dns": ["178.22.122.100", "185.51.200.2"],
  "registry-mirrors": [
"https://docker.arvancloud.ir",
"https://registry.docker.ir"
  ]
}
EOF

sudo systemctl restart docker
docker info | grep -A 5 "Registry Mirrors"

## 6. Pull & Run V2RayA

bash
docker pull mzz2017/v2raya

docker run -d \
  --restart=always \
  --privileged \
  --network=host \
  --name v2raya \
  -e V2RAYA_LOG_LEVEL=info \
  -v /lib/modules:/lib/modules:ro \
  -v /etc/resolv.conf:/etc/resolv.conf \
  -v /etc/v2raya:/etc/v2raya \
  mzz2017/v2raya

## 7. Verify

bash
docker ps
docker logs v2raya
# Web UI: http://localhost:2017
