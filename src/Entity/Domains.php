<?php

namespace App\Entity;

use App\Repository\DomainsRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DomainsRepository::class)]
#[ORM\Table(name: 'domains')]
class Domains
{
    #[ORM\Id]
    #[ORM\Column(name: 'id', type: 'bigint')]
    #[ORM\GeneratedValue(strategy: 'IDENTITY')]
    private ?int $id = null;

    #[ORM\Column(name: 'titulo', type: 'string', length: 200)]
    private string $titulo = '';

    #[ORM\Column(name: 'valor', type: 'string', length: 5000)]
    private string $valor = '';

    #[ORM\Column(name: 'nombre', type: 'string', length: 200)]
    private string $nombre = '';

    #[ORM\Column(name: 'idioma', type: 'string', length: 6)]
    private string $idioma = '';

    #[ORM\Column(name: 'estado', type: 'boolean')]
    private bool $estado = true;

    #[ORM\Column(name: 'mostrar', type: 'boolean')]
    private bool $mostrar = true;

    public function getId(): ?int { return $this->id; }
    public function getTitulo(): string { return $this->titulo; }
    public function setTitulo(string $v): self { $this->titulo = $v; return $this; }
    public function getValor(): string { return $this->valor; }
    public function setValor(string $v): self { $this->valor = $v; return $this; }
    public function getNombre(): string { return $this->nombre; }
    public function setNombre(string $v): self { $this->nombre = $v; return $this; }
    public function getIdioma(): string { return $this->idioma; }
    public function setIdioma(string $v): self { $this->idioma = $v; return $this; }
    public function isEstado(): bool { return $this->estado; }
    public function setEstado(bool $v): self { $this->estado = $v; return $this; }
    public function isMostrar(): bool { return $this->mostrar; }
    public function setMostrar(bool $v): self { $this->mostrar = $v; return $this; }
}
