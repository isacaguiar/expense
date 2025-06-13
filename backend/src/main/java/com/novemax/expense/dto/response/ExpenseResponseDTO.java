package com.novemax.expense.dto.response;

import com.novemax.expense.dto.GroupDTO;
import com.novemax.expense.dto.UserDTO;
import com.novemax.expense.model.ExpenseType;
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
public class ExpenseResponseDTO {

  private UUID id;
  private String description;
  private BigDecimal totalValue;
  private LocalDate paymentDate;
  private ExpenseType type;
  private Integer installments;

  private GroupDTO group;
  private UserDTO userCreator;
  private UserDTO userPayer;
  private List<UserDTO> payers;
}
