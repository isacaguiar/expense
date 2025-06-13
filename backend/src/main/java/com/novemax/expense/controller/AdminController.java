package com.novemax.expense.controller;

import io.swagger.v3.oas.annotations.tags.Tag;
import lombok.extern.slf4j.Slf4j;
import org.springframework.security.access.prepost.PreAuthorize;
import org.springframework.web.bind.annotation.*;

@Slf4j
@RestController
@RequestMapping("/admin")
@Tag(name = "Administration")
public class AdminController {

    @GetMapping("/panel")
    @PreAuthorize("hasRole('ADMIN')")
    public String adminPanel() {
        log.info("Entrou no painel admin!");
        return "Acesso ao painel do administrador concedido!";
    }
}