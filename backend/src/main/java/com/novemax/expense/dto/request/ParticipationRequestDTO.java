package com.novemax.expense.dto.request;

import jakarta.validation.constraints.NotNull;
import lombok.AllArgsConstructor;
import lombok.Builder;
import lombok.Data;
import lombok.NoArgsConstructor;

import java.math.BigDecimal;
import java.util.UUID;

@Data
@Builder
@NoArgsConstructor
@AllArgsConstructor
public class ParticipationRequestDTO {
  @NotNull
  private UUID quotaId;

  @NotNull
  private UUID userId;

  @NotNull
  private BigDecimal value;

  @NotNull
  private String state; // PENDENTE ou PAGO
}
