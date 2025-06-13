package com.novemax.expense.dto.request;

import com.novemax.expense.model.ExpenseType;
import jakarta.validation.constraints.DecimalMin;
import jakarta.validation.constraints.NotBlank;
import jakarta.validation.constraints.NotEmpty;
import jakarta.validation.constraints.NotNull;
import java.math.BigDecimal;
import java.time.LocalDate;
import java.util.List;
import java.util.UUID;
import lombok.AllArgsConstructor;
import lombok.Builder;
import lombok.Data;
import lombok.NoArgsConstructor;

@Data
@Builder
@NoArgsConstructor
@AllArgsConstructor
public class ExpenseRequestDTO {

  @NotBlank
  private String description;

  @NotNull
  @DecimalMin("0.01")
  private BigDecimal totalValue;

  @NotNull
  private LocalDate paymentDate;

  @NotNull
  private ExpenseType type;

  private Integer installments; // Apenas se for parcelada

  @NotNull
  private UUID groupId;

  @NotNull
  private UUID userPayerId;

  @NotNull
  private UUID userCreatorId;

  @NotEmpty
  private List<UUID> payerIds;
}
