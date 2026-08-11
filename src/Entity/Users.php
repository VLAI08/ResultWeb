<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: \App\Repository\UsersRepository::class)]
#[ORM\Table(name: 'users')]
class Users
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'bigint')]
    private ?string $id = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $names = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $lastnames = '';

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $identification = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $identificationtype = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $password = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $phones = '';

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $contact = '';

    #[ORM\Column(name: 'phone_contact', type: 'string', nullable: true)]
    private ?string $phoneContact = '';

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $type = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $isadmin = false;

    #[ORM\Column(type: 'boolean', nullable: true)]
    private ?bool $active = true;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $code = '';

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $urlimg = '';

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $footer = '';

    #[ORM\Column(name: 'download_options', type: 'string', nullable: true)]
    private ?string $download_options = '';

    #[ORM\Column(name: 'logo_options', type: 'string', nullable: true)]
    private ?string $logo_options = '';

    #[ORM\Column(name: 'type_admin', type: 'string', nullable: true)]
    private ?string $type_admin = '';

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $address = '';

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $sex = '';

    #[ORM\Column(name: 'password_changed', type: 'boolean', nullable: true)]
    private ?bool $password_changed = false;

    #[ORM\Column(name: 'reset_code', type: 'string', nullable: true)]
    private ?string $reset_code = null;

    #[ORM\Column(name: 'code_expires_at', type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $code_expires_at = null;

    public function getId(): ?string { return $this->id; }
    public function setId(?string $id): self { $this->id = $id; return $this; }

    public function getNames(): ?string { return $this->names; }
    public function setNames(?string $names): self { $this->names = $names; return $this; }

    public function getLastnames(): ?string { return $this->lastnames; }
    public function setLastnames(?string $lastnames): self { $this->lastnames = $lastnames; return $this; }

    public function getIdentification(): ?string { return $this->identification; }
    public function setIdentification(?string $identification): self { $this->identification = $identification; return $this; }

    public function getIdentificationtype(): ?string { return $this->identificationtype; }
    public function setIdentificationtype(?string $identificationtype): self { $this->identificationtype = $identificationtype; return $this; }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(?string $password): self { $this->password = $password; return $this; }

    public function getPhones(): ?string { return $this->phones; }
    public function setPhones(?string $phones): self { $this->phones = $phones; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): self { $this->email = $email; return $this; }

    public function getContact(): ?string { return $this->contact; }
    public function setContact(?string $contact): self { $this->contact = $contact; return $this; }

    public function getPhoneContact(): ?string { return $this->phoneContact; }
    public function setPhoneContact(?string $phoneContact): self { $this->phoneContact = $phoneContact; return $this; }

    public function getType(): ?string { return $this->type; }
    public function setType(?string $type): self { $this->type = $type; return $this; }

    public function getIsadmin(): ?bool { return $this->isadmin; }
    public function setIsadmin(?bool $isadmin): self { $this->isadmin = $isadmin; return $this; }

    public function getActive(): ?bool { return $this->active; }
    public function setActive(?bool $active): self { $this->active = $active; return $this; }

    public function getCode(): ?string { return $this->code; }
    public function setCode(?string $code): self { $this->code = $code; return $this; }

    public function getUrlimg(): ?string { return $this->urlimg; }
    public function setUrlimg(?string $urlimg): self { $this->urlimg = $urlimg; return $this; }

    public function getFooter(): ?string { return $this->footer; }
    public function setFooter(?string $footer): self { $this->footer = $footer; return $this; }

    public function getDownloadOptions(): ?string { return $this->download_options; }
    public function setDownloadOptions(?string $v): self { $this->download_options = $v; return $this; }

    public function getLogoOptions(): ?string { return $this->logo_options; }
    public function setLogoOptions(?string $v): self { $this->logo_options = $v; return $this; }

    public function getTypeAdmin(): ?string { return $this->type_admin; }
    public function setTypeAdmin(?string $v): self { $this->type_admin = $v; return $this; }

    public function getAddress(): ?string { return $this->address; }
    public function setAddress(?string $v): self { $this->address = $v; return $this; }

    public function getSex(): ?string { return $this->sex; }
    public function setSex(?string $v): self { $this->sex = $v; return $this; }

    public function getPasswordChanged(): ?bool { return $this->password_changed; }
    public function setPasswordChanged(?bool $v): self { $this->password_changed = $v; return $this; }

    public function getResetCode(): ?string { return $this->reset_code; }
    public function setResetCode(?string $v): self { $this->reset_code = $v; return $this; }

    public function getCodeExpiresAt(): ?\DateTimeInterface { return $this->code_expires_at; }
    public function setCodeExpiresAt(?\DateTimeInterface $v): self { $this->code_expires_at = $v; return $this; }

    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'names' => $this->getNames(),
            'lastnames' => $this->getLastnames(),
            'identification' => $this->getIdentification(),
            'identificationtype' => $this->getIdentificationtype(),
            'password' => $this->getPassword(),
            'phones' => $this->getPhones(),
            'email' => $this->getEmail(),
            'contact' => $this->getContact(),
            'phoneContact' => $this->getPhoneContact(),
            'type' => $this->getType(),
            'isadmin' => $this->getIsadmin(),
            'active' => $this->getActive(),
            'code' => $this->getCode(),
            'urlimg' => $this->getUrlimg(),
            'footer' => $this->getFooter(),
            'download_options' => $this->getDownloadOptions(),
            'logo_options' => $this->getLogoOptions(),
            'type_admin' => $this->getTypeAdmin(),
            'address' => $this->getAddress(),
            'sex' => $this->getSex(),
            'password_changed' => (bool) $this->getPasswordChanged(),
        ];
    }
}
