package com.novemax.expense.dto;

import java.math.BigDecimal;
import java.time.LocalDate;
import java.util.UUID;

public class QuotaDTO {
    private UUID id;
    private Integer numero;
    private BigDecimal valor;
    private LocalDate dataPrevista;
    private boolean foiPaga;
}